<?php

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TeamRepository::class)]
class Team
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'teams')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tournament $tournament = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Player $player1 = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Player $player2 = null;

    #[ORM\Column(length: 180)]
    private string $name = '';

    #[ORM\Column]
    private int $seed = 0;

    #[ORM\ManyToOne(inversedBy: 'teams')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TournamentGroup $group = null;

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

    public function getPlayer1(): ?Player
    {
        return $this->player1;
    }

    public function setPlayer1(Player $player1): self
    {
        $this->player1 = $player1;

        return $this;
    }

    public function getPlayer2(): ?Player
    {
        return $this->player2;
    }

    public function setPlayer2(?Player $player2): self
    {
        $this->player2 = $player2;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    public function setSeed(int $seed): self
    {
        $this->seed = $seed;

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

    /** @return list<Player> */
    public function getPlayers(): array
    {
        $players = [];
        if ($this->player1) {
            $players[] = $this->player1;
        }
        if ($this->player2) {
            $players[] = $this->player2;
        }

        return $players;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'seed' => $this->seed,
            'groupId' => $this->group?->getId(),
            'player1' => $this->player1?->toArray(),
            'player2' => $this->player2?->toArray(),
        ];
    }
}
