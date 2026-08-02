<?php

namespace App\Entity;

use App\Repository\PlayerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlayerRepository::class)]
class Player
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column]
    private int $wins = 0;

    #[ORM\Column]
    private int $losses = 0;

    #[ORM\Column]
    private int $tournamentsPlayed = 0;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getWins(): int
    {
        return $this->wins;
    }

    public function setWins(int $wins): self
    {
        $this->wins = $wins;

        return $this;
    }

    public function addWin(): self
    {
        ++$this->wins;

        return $this;
    }

    public function removeWin(): self
    {
        if ($this->wins > 0) {
            --$this->wins;
        }

        return $this;
    }

    public function getLosses(): int
    {
        return $this->losses;
    }

    public function setLosses(int $losses): self
    {
        $this->losses = $losses;

        return $this;
    }

    public function addLoss(): self
    {
        ++$this->losses;

        return $this;
    }

    public function removeLoss(): self
    {
        if ($this->losses > 0) {
            --$this->losses;
        }

        return $this;
    }

    public function getTournamentsPlayed(): int
    {
        return $this->tournamentsPlayed;
    }

    public function setTournamentsPlayed(int $tournamentsPlayed): self
    {
        $this->tournamentsPlayed = $tournamentsPlayed;

        return $this;
    }

    public function incrementTournamentsPlayed(): self
    {
        ++$this->tournamentsPlayed;

        return $this;
    }

    public function decrementTournamentsPlayed(): self
    {
        if ($this->tournamentsPlayed > 0) {
            --$this->tournamentsPlayed;
        }

        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'wins' => $this->wins,
            'losses' => $this->losses,
            'tournamentsPlayed' => $this->tournamentsPlayed,
        ];
    }
}
