<?php

namespace App\Entity;

use App\Repository\GroupStandingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GroupStandingRepository::class)]
class GroupStanding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'standings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TournamentGroup $group = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Team $team = null;

    #[ORM\Column]
    private int $played = 0;

    #[ORM\Column]
    private int $wins = 0;

    #[ORM\Column]
    private int $losses = 0;

    #[ORM\Column]
    private int $points = 0;

    #[ORM\Column]
    private int $scoreFor = 0;

    #[ORM\Column]
    private int $scoreAgainst = 0;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function setTeam(Team $team): self
    {
        $this->team = $team;

        return $this;
    }

    public function getPlayed(): int
    {
        return $this->played;
    }

    public function getWins(): int
    {
        return $this->wins;
    }

    public function getLosses(): int
    {
        return $this->losses;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function getScoreFor(): int
    {
        return $this->scoreFor;
    }

    public function getScoreAgainst(): int
    {
        return $this->scoreAgainst;
    }

    public function getScoreDiff(): int
    {
        return $this->scoreFor - $this->scoreAgainst;
    }

    public function registerResult(bool $won, ?int $scoreFor, ?int $scoreAgainst): void
    {
        ++$this->played;
        if ($won) {
            ++$this->wins;
            $this->points += 3;
        } else {
            ++$this->losses;
        }
        if (null !== $scoreFor) {
            $this->scoreFor += $scoreFor;
        }
        if (null !== $scoreAgainst) {
            $this->scoreAgainst += $scoreAgainst;
        }
    }

    public function revertResult(bool $won, ?int $scoreFor, ?int $scoreAgainst): void
    {
        if ($this->played > 0) {
            --$this->played;
        }
        if ($won) {
            if ($this->wins > 0) {
                --$this->wins;
            }
            $this->points = max(0, $this->points - 3);
        } elseif ($this->losses > 0) {
            --$this->losses;
        }
        if (null !== $scoreFor) {
            $this->scoreFor = max(0, $this->scoreFor - $scoreFor);
        }
        if (null !== $scoreAgainst) {
            $this->scoreAgainst = max(0, $this->scoreAgainst - $scoreAgainst);
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'team' => $this->team?->toArray(),
            'played' => $this->played,
            'wins' => $this->wins,
            'losses' => $this->losses,
            'points' => $this->points,
            'scoreFor' => $this->scoreFor,
            'scoreAgainst' => $this->scoreAgainst,
            'scoreDiff' => $this->getScoreDiff(),
        ];
    }
}
