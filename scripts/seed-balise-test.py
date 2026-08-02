#!/usr/bin/env python3
"""Balise de test : crée des tournois et exerce correction / progression."""

from __future__ import annotations

import json
import sys
import urllib.error
import urllib.request

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080"
TOKEN = ""


def req(method: str, path: str, body: dict | None = None, auth: bool = True):
    data = None if body is None else json.dumps(body).encode()
    headers = {"Content-Type": "application/json"}
    if auth and TOKEN:
        headers["X-Admin-Token"] = TOKEN
    request = urllib.request.Request(
        f"{BASE}{path}", data=data, headers=headers, method=method
    )
    try:
        with urllib.request.urlopen(request) as resp:
            raw = resp.read().decode()
            return resp.status, json.loads(raw) if raw else None
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            payload = json.loads(raw) if raw else {"error": str(e)}
        except json.JSONDecodeError:
            payload = {"error": raw or str(e)}
        return e.code, payload


def ok(status: int, data, label: str):
    if 200 <= status < 300:
        print(f"  ✓ {label}")
        return data
    err = data.get("error") if isinstance(data, dict) else data
    print(f"  ✗ {label} → {status}: {err}")
    raise SystemExit(1)


def expect_fail(status: int, data, label: str):
    if 200 <= status < 300:
        print(f"  ✗ {label} (aurait dû échouer)")
        raise SystemExit(1)
    print(f"  ✓ {label} (refusé comme prévu: {data.get('error', status)})")


def login():
    global TOKEN
    _, data = req("POST", "/api/login", {"username": "admin", "password": "admin"}, auth=False)
    TOKEN = data["token"]
    print(f"Connecté ({BASE})")


def get_tournament(tid: int):
    _, data = req("GET", f"/api/tournaments/{tid}")
    return data


def set_result(match: dict, winner_id: int, sh: int = 2, sa: int = 0):
    home_id = match["homeTeam"]["id"]
    away_id = match["awayTeam"]["id"]
    if winner_id == home_id:
        score_home, score_away = max(sh, sa + 1), min(sh, sa)
    else:
        score_home, score_away = min(sh, sa), max(sh, sa + 1)
    status, data = req(
        "POST",
        f"/api/matches/{match['id']}/result",
        {"winnerId": winner_id, "scoreHome": score_home, "scoreAway": score_away},
    )
    return status, data


def playable(matches):
    return [
        m
        for m in matches
        if m["status"] != "done" and m.get("homeTeam") and m.get("awayTeam")
    ]


def sort_matches(matches):
    return sorted(matches, key=lambda m: (m.get("sortOrder") or 0, m["id"]))


def create_tournament(name: str, **kwargs):
    payload = {
        "name": name,
        "hasGroupStage": True,
        "bracketType": "double",
        "teamMode": "solo",
        "groupCount": 2,
        "qualifiersPerGroup": 2,
        **kwargs,
    }
    status, data = req("POST", "/api/tournaments", payload)
    return ok(status, data, f"Création « {name} »")


def register_players(tid: int, names: list[str]):
    for name in names:
        status, data = req("POST", f"/api/tournaments/{tid}/register", {"name": name})
        ok(status, data, f"Inscription {name}")


def finish_all_playable(tid: int, prefer_home: bool = True, limit: int | None = None):
    played = 0
    while True:
        t = get_tournament(tid)
        open_matches = sort_matches(playable(t["matches"]))
        if not open_matches:
            break
        if limit is not None and played >= limit:
            break
        m = open_matches[0]
        winner = m["homeTeam"]["id"] if prefer_home else m["awayTeam"]["id"]
        # Alternate a bit for variety
        if played % 3 == 2:
            winner = m["awayTeam"]["id"] if prefer_home else m["homeTeam"]["id"]
        status, data = set_result(m, winner)
        ok(status, data, f"Résultat match #{m['id']} ({m['phase']} R{m.get('round')})")
        played += 1
    return get_tournament(tid)


def seed_interactive_double():
    """Tournoi prêt à manipuler : poules terminées, bracket ouvert, 1er tour partiel + correction."""
    print("\n=== Tournoi A — balise interactive (double élim, poules) ===")
    players = [
        "Alice",
        "Bruno",
        "Chloé",
        "Diego",
        "Emma",
        "Farid",
        "Gina",
        "Hugo",
    ]
    t = create_tournament("Balise test — double + poules")
    tid = t["id"]
    register_players(tid, players)

    status, data = req("POST", f"/api/tournaments/{tid}/generate-groups")
    ok(status, data, "Génération poules")

    # Jouer tous les matchs de poule sauf le dernier, corriger un résultat, puis finir
    t = get_tournament(tid)
    groups = [m for m in sort_matches(t["matches"]) if m["phase"] == "group"]
    assert len(groups) >= 2

    first = groups[0]
    status, data = set_result(first, first["homeTeam"]["id"], 2, 0)
    ok(status, data, f"Poule match #{first['id']} → home")

    # Correction : inverser le vainqueur
    status, data = set_result(first, first["awayTeam"]["id"], 0, 2)
    ok(status, data, f"Correction poule #{first['id']} → away (retour arrière)")

    # Finir le reste des poules
    finish_all_playable(tid)

    # Résoudre d’éventuels barrages (égalités)
    t = get_tournament(tid)
    ties = (t.get("groupStage") or {}).get("ties") or []
    for tie in ties:
        status, data = req(
            "POST",
            f"/api/tournaments/{tid}/tiebreakers",
            {"groupId": tie["groupId"], "mode": "melee"},
        )
        ok(status, data, f"Barrage poule {tie.get('groupName', tie['groupId'])}")
    if ties:
        finish_all_playable(tid)

    status, data = req("POST", f"/api/tournaments/{tid}/generate-bracket")
    ok(status, data, "Génération bracket double")

    t = get_tournament(tid)
    wb1 = [
        m
        for m in sort_matches(t["matches"])
        if m["phase"] == "winner" and m.get("round") == 1 and m.get("homeTeam") and m.get("awayTeam")
    ]
    if not wb1:
        print("  ! Aucun match WB R1 prêt — état laissé tel quel")
        return tid

    m0 = wb1[0]
    status, data = set_result(m0, m0["homeTeam"]["id"])
    ok(status, data, f"WB R1 #{m0['id']} → home")

    # Correction avant que les équipes rejouent
    status, data = set_result(m0, m0["awayTeam"]["id"])
    ok(status, data, f"Correction WB #{m0['id']} → away")

    # Jouer un 2e match WB pour bloquer la correction du 1er si même équipe
    t = get_tournament(tid)
    open_wb = [
        m
        for m in playable(t["matches"])
        if m["phase"] == "winner"
    ]
    if open_wb:
        m1 = sort_matches(open_wb)[0]
        status, data = set_result(m1, m1["homeTeam"]["id"])
        ok(status, data, f"WB suivant #{m1['id']}")

    # Tentative de correction d’un match dont une équipe a rejoué → doit échouer si applicable
    t = get_tournament(tid)
    done_wb = [
        m
        for m in t["matches"]
        if m["phase"] == "winner" and m["status"] == "done" and not m.get("editable", True)
    ]
    if done_wb:
        blocked = done_wb[0]
        other = (
            blocked["awayTeam"]["id"]
            if blocked["winner"]["id"] == blocked["homeTeam"]["id"]
            else blocked["homeTeam"]["id"]
        )
        status, data = set_result(blocked, other)
        expect_fail(status, data, f"Correction bloquée match #{blocked['id']}")
    else:
        # Si tous encore éditables, vérifier qu’au moins un l’est
        editable = [m for m in t["matches"] if m.get("editable")]
        print(f"  · {len(editable)} match(s) encore corrigibles")

    # Projection sur un match courant
    t = get_tournament(tid)
    pending = playable(t["matches"])
    if pending:
        cur = sort_matches(pending)[0]
        nxt = sort_matches(pending)[1] if len(pending) > 1 else None
        status, data = req(
            "PATCH",
            f"/api/tournaments/{tid}/display",
            {"currentMatchId": cur["id"], "nextMatchId": nxt["id"] if nxt else None},
        )
        ok(status, data, f"Projection → match #{cur['id']}")

    return tid


def seed_simple_midgame():
    """Tournoi simple élimination, sans poules, mid-bracket pour tester téléphone / projection."""
    print("\n=== Tournoi B — simple élim, sans poules (mid-game) ===")
    players = ["Iris", "Jules", "Kara", "Leo", "Maya", "Noé", "Owen", "Pia"]
    t = create_tournament(
        "Balise test — simple mid-game",
        hasGroupStage=False,
        bracketType="single",
        groupCount=1,
        qualifiersPerGroup=1,
    )
    tid = t["id"]
    register_players(tid, players)

    status, data = req("POST", f"/api/tournaments/{tid}/generate-bracket")
    ok(status, data, "Génération bracket simple")

    # Jouer la moitié des matchs du 1er tour
    t = get_tournament(tid)
    r1 = [
        m
        for m in sort_matches(t["matches"])
        if m["phase"] in ("winner", "final") and m.get("homeTeam") and m.get("awayTeam") and m["status"] != "done"
    ]
    # Prefer round 1
    r1 = [m for m in r1 if m.get("round") == 1] or r1
    for i, m in enumerate(r1[:2]):
        winner = m["homeTeam"]["id"] if i % 2 == 0 else m["awayTeam"]["id"]
        status, data = set_result(m, winner)
        ok(status, data, f"QF #{m['id']}")

    # Correction du dernier joué
    last = r1[1]
    flip = (
        last["awayTeam"]["id"]
        if last.get("winner") and last["winner"]["id"] == last["homeTeam"]["id"]
        else last["homeTeam"]["id"]
    )
    # Recharger pour avoir winner
    t = get_tournament(tid)
    last = next(m for m in t["matches"] if m["id"] == last["id"])
    flip = (
        last["awayTeam"]["id"]
        if last["winner"]["id"] == last["homeTeam"]["id"]
        else last["homeTeam"]["id"]
    )
    status, data = set_result(last, flip)
    ok(status, data, f"Correction QF #{last['id']}")

    pending = playable(get_tournament(tid)["matches"])
    if pending:
        cur = sort_matches(pending)[0]
        status, data = req(
            "PATCH",
            f"/api/tournaments/{tid}/display",
            {"currentMatchId": cur["id"], "nextMatchId": None},
        )
        ok(status, data, f"Projection → #{cur['id']}")

    return tid


def seed_duo_registration():
    """Tournoi duo en inscription pour tester tirage / UI."""
    print("\n=== Tournoi C — duo en inscription ===")
    t = create_tournament(
        "Balise test — duo inscriptions",
        hasGroupStage=True,
        bracketType="single",
        teamMode="duo",
        groupCount=2,
        qualifiersPerGroup=2,
    )
    tid = t["id"]
    names = [
        "Quentin",
        "Rita",
        "Sam",
        "Tina",
        "Ugo",
        "Vera",
        "Will",
        "Xena",
        "Yann",
        "Zoé",
        "Axel",
        "Béa",
    ]
    status, data = req(
        "POST",
        f"/api/tournaments/{tid}/register-players",
        {"names": names},
    )
    ok(status, data, f"Liste duo ({len(names)} joueurs)")
    return tid


def main():
    login()
    a = seed_interactive_double()
    b = seed_simple_midgame()
    c = seed_duo_registration()

    print("\n========== URLs de test ==========")
    print(f"Admin UI (Vite)     : http://localhost:5173/")
    print(f"Login               : admin / admin")
    print(f"Tournoi A (double)  : http://localhost:5173/tournaments/{a}")
    print(f"  Téléphone         : http://localhost:5173/manage/{a}")
    print(f"  Projection        : http://localhost:5173/display/{a}")
    print(f"Tournoi B (simple)  : http://localhost:5173/tournaments/{b}")
    print(f"  Téléphone         : http://localhost:5173/manage/{b}")
    print(f"  Projection        : http://localhost:5173/display/{b}")
    print(f"Tournoi C (duo)     : http://localhost:5173/tournaments/{c}")
    print("==================================")
    print("Cas couverts côté A : correction poule, correction WB, refus si équipe a rejoué.")
    print("Cas couverts côté B : mid-game simple + correction QF.")
    print("Cas couverts côté C : inscriptions duo prêtes pour tirage.")


if __name__ == "__main__":
    main()
