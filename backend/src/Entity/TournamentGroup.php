<?php

namespace App\Entity;

use App\Repository\TournamentGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TournamentGroupRepository::class)]
class TournamentGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'groups')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tournament $tournament = null;

    #[ORM\Column(length: 40)]
    private string $name = '';

    /** @var Collection<int, Team> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: Team::class)]
    private Collection $teams;

    /** @var Collection<int, GroupStanding> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: GroupStanding::class, orphanRemoval: true, cascade: ['persist'])]
    private Collection $standings;

    public function __construct()
    {
        $this->teams = new ArrayCollection();
        $this->standings = new ArrayCollection();
    }

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /** @return Collection<int, Team> */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    /** @return Collection<int, GroupStanding> */
    public function getStandings(): Collection
    {
        return $this->standings;
    }

    public function addStanding(GroupStanding $standing): self
    {
        if (!$this->standings->contains($standing)) {
            $this->standings->add($standing);
            $standing->setGroup($this);
        }

        return $this;
    }

    public function toArray(): array
    {
        $standings = $this->standings->toArray();
        usort($standings, static function (GroupStanding $a, GroupStanding $b): int {
            return [$b->getPoints(), $b->getWins(), $b->getScoreDiff(), $a->getTeam()?->getSeed() ?? 0]
                <=> [$a->getPoints(), $a->getWins(), $a->getScoreDiff(), $b->getTeam()?->getSeed() ?? 0];
        });

        return [
            'id' => $this->id,
            'name' => $this->name,
            'teams' => array_map(static fn (Team $t) => $t->toArray(), $this->teams->toArray()),
            'standings' => array_map(static fn (GroupStanding $s) => $s->toArray(), $standings),
        ];
    }
}
