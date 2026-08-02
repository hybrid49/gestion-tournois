<?php

namespace App\Entity;

use App\Enum\MatchPhase;
use App\Enum\MatchStatus;
use App\Repository\GameMatchRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameMatchRepository::class)]
#[ORM\Table(name: 'game_match')]
class GameMatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'matches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tournament $tournament = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TournamentGroup $group = null;

    #[ORM\Column(enumType: MatchPhase::class)]
    private MatchPhase $phase = MatchPhase::Group;

    #[ORM\Column]
    private int $round = 1;

    #[ORM\Column]
    private int $slot = 1;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Team $homeTeam = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Team $awayTeam = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Team $winner = null;

    #[ORM\Column(nullable: true)]
    private ?int $scoreHome = null;

    #[ORM\Column(nullable: true)]
    private ?int $scoreAway = null;

    #[ORM\Column(enumType: MatchStatus::class)]
    private MatchStatus $status = MatchStatus::Pending;

    #[ORM\Column(nullable: true)]
    private ?int $nextMatchId = null;

    #[ORM\Column(nullable: true)]
    private ?bool $nextMatchIsHome = null;

    #[ORM\Column(nullable: true)]
    private ?int $loserNextMatchId = null;

    #[ORM\Column(nullable: true)]
    private ?bool $loserNextMatchIsHome = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTournament(): ?Tournament
    {
        return $this->tournament;
    }

    public function setTournament(?Tournament $tournament): self
    {
        $this->tournament = $tournament;

        return $this;
    }

    public function getGroup(): ?TournamentGroup
    {
        return $this->group;
    }

    public function setGroup(?TournamentGroup $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function getPhase(): MatchPhase
    {
        return $this->phase;
    }

    public function setPhase(MatchPhase $phase): self
    {
        $this->phase = $phase;

        return $this;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function setRound(int $round): self
    {
        $this->round = $round;

        return $this;
    }

    public function getSlot(): int
    {
        return $this->slot;
    }

    public function setSlot(int $slot): self
    {
        $this->slot = $slot;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getHomeTeam(): ?Team
    {
        return $this->homeTeam;
    }

    public function setHomeTeam(?Team $homeTeam): self
    {
        $this->homeTeam = $homeTeam;

        return $this;
    }

    public function getAwayTeam(): ?Team
    {
        return $this->awayTeam;
    }

    public function setAwayTeam(?Team $awayTeam): self
    {
        $this->awayTeam = $awayTeam;

        return $this;
    }

    public function getWinner(): ?Team
    {
        return $this->winner;
    }

    public function setWinner(?Team $winner): self
    {
        $this->winner = $winner;

        return $this;
    }

    public function getScoreHome(): ?int
    {
        return $this->scoreHome;
    }

    public function setScoreHome(?int $scoreHome): self
    {
        $this->scoreHome = $scoreHome;

        return $this;
    }

    public function getScoreAway(): ?int
    {
        return $this->scoreAway;
    }

    public function setScoreAway(?int $scoreAway): self
    {
        $this->scoreAway = $scoreAway;

        return $this;
    }

    public function getStatus(): MatchStatus
    {
        return $this->status;
    }

    public function setStatus(MatchStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getNextMatchId(): ?int
    {
        return $this->nextMatchId;
    }

    public function setNextMatchId(?int $nextMatchId): self
    {
        $this->nextMatchId = $nextMatchId;

        return $this;
    }

    public function getNextMatchIsHome(): ?bool
    {
        return $this->nextMatchIsHome;
    }

    public function setNextMatchIsHome(?bool $nextMatchIsHome): self
    {
        $this->nextMatchIsHome = $nextMatchIsHome;

        return $this;
    }

    public function getLoserNextMatchId(): ?int
    {
        return $this->loserNextMatchId;
    }

    public function setLoserNextMatchId(?int $loserNextMatchId): self
    {
        $this->loserNextMatchId = $loserNextMatchId;

        return $this;
    }

    public function getLoserNextMatchIsHome(): ?bool
    {
        return $this->loserNextMatchIsHome;
    }

    public function setLoserNextMatchIsHome(?bool $loserNextMatchIsHome): self
    {
        $this->loserNextMatchIsHome = $loserNextMatchIsHome;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'phase' => $this->phase->value,
            'round' => $this->round,
            'slot' => $this->slot,
            'sortOrder' => $this->sortOrder,
            'groupId' => $this->group?->getId(),
            'homeTeam' => $this->homeTeam?->toArray(),
            'awayTeam' => $this->awayTeam?->toArray(),
            'winner' => $this->winner?->toArray(),
            'scoreHome' => $this->scoreHome,
            'scoreAway' => $this->scoreAway,
            'status' => $this->status->value,
            'nextMatchId' => $this->nextMatchId,
            'nextMatchIsHome' => $this->nextMatchIsHome,
            'loserNextMatchId' => $this->loserNextMatchId,
            'loserNextMatchIsHome' => $this->loserNextMatchIsHome,
        ];
    }
}
