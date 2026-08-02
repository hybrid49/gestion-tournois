<?php

namespace App\Entity;

use App\Enum\BracketType;
use App\Enum\MatchPhase;
use App\Enum\MatchStatus;
use App\Enum\TeamMode;
use App\Enum\TournamentStatus;
use App\Repository\TournamentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TournamentRepository::class)]
class Tournament
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $name = '';

    #[ORM\Column]
    private bool $hasGroupStage = true;

    #[ORM\Column(enumType: BracketType::class)]
    private BracketType $bracketType = BracketType::Single;

    #[ORM\Column(enumType: TeamMode::class)]
    private TeamMode $teamMode = TeamMode::Solo;

    #[ORM\Column]
    private int $groupCount = 2;

    #[ORM\Column]
    private int $qualifiersPerGroup = 2;

    #[ORM\Column(enumType: TournamentStatus::class)]
    private TournamentStatus $status = TournamentStatus::Draft;

    #[ORM\Column(nullable: true)]
    private ?int $currentMatchId = null;

    #[ORM\Column(nullable: true)]
    private ?int $nextMatchId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Team> */
    #[ORM\OneToMany(mappedBy: 'tournament', targetEntity: Team::class, orphanRemoval: true)]
    private Collection $teams;

    /** @var Collection<int, TournamentGroup> */
    #[ORM\OneToMany(mappedBy: 'tournament', targetEntity: TournamentGroup::class, orphanRemoval: true)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $groups;

    /** @var Collection<int, GameMatch> */
    #[ORM\OneToMany(mappedBy: 'tournament', targetEntity: GameMatch::class, orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $matches;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->teams = new ArrayCollection();
        $this->groups = new ArrayCollection();
        $this->matches = new ArrayCollection();
    }

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

    public function hasGroupStage(): bool
    {
        return $this->hasGroupStage;
    }

    public function setHasGroupStage(bool $hasGroupStage): self
    {
        $this->hasGroupStage = $hasGroupStage;

        return $this;
    }

    public function getBracketType(): BracketType
    {
        return $this->bracketType;
    }

    public function setBracketType(BracketType $bracketType): self
    {
        $this->bracketType = $bracketType;

        return $this;
    }

    public function getTeamMode(): TeamMode
    {
        return $this->teamMode;
    }

    public function setTeamMode(TeamMode $teamMode): self
    {
        $this->teamMode = $teamMode;

        return $this;
    }

    public function getGroupCount(): int
    {
        return $this->groupCount;
    }

    public function setGroupCount(int $groupCount): self
    {
        $this->groupCount = $groupCount;

        return $this;
    }

    public function getQualifiersPerGroup(): int
    {
        return $this->qualifiersPerGroup;
    }

    public function setQualifiersPerGroup(int $qualifiersPerGroup): self
    {
        $this->qualifiersPerGroup = $qualifiersPerGroup;

        return $this;
    }

    public function getStatus(): TournamentStatus
    {
        return $this->status;
    }

    public function setStatus(TournamentStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCurrentMatchId(): ?int
    {
        return $this->currentMatchId;
    }

    public function setCurrentMatchId(?int $currentMatchId): self
    {
        $this->currentMatchId = $currentMatchId;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Team> */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    public function addTeam(Team $team): self
    {
        if (!$this->teams->contains($team)) {
            $this->teams->add($team);
            $team->setTournament($this);
        }

        return $this;
    }

    /** @return Collection<int, TournamentGroup> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(TournamentGroup $group): self
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
            $group->setTournament($this);
        }

        return $this;
    }

    /** @return Collection<int, GameMatch> */
    public function getMatches(): Collection
    {
        return $this->matches;
    }

    public function addMatch(GameMatch $match): self
    {
        if (!$this->matches->contains($match)) {
            $this->matches->add($match);
            $match->setTournament($this);
        }

        return $this;
    }

    public function toArray(bool $detailed = false): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'hasGroupStage' => $this->hasGroupStage,
            'bracketType' => $this->bracketType->value,
            'teamMode' => $this->teamMode->value,
            'groupCount' => $this->groupCount,
            'qualifiersPerGroup' => $this->qualifiersPerGroup,
            'status' => $this->status->value,
            'currentMatchId' => $this->currentMatchId,
            'nextMatchId' => $this->nextMatchId,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'teamCount' => $this->teams->count(),
        ];

        if ($detailed) {
            $data['teams'] = array_map(static fn (Team $t) => $t->toArray(), $this->teams->toArray());
            $data['groups'] = array_map(static fn (TournamentGroup $g) => $g->toArray(), $this->groups->toArray());
            $data['matches'] = array_map(static fn (GameMatch $m) => $m->toArray(), $this->matches->toArray());
            $data['groupStage'] = $this->groupStageProgress();
        }

        return $data;
    }

    public function groupStageProgress(): array
    {
        if (!$this->hasGroupStage) {
            return ['total' => 0, 'done' => 0, 'complete' => true];
        }

        $groupMatches = array_values(array_filter(
            $this->matches->toArray(),
            static fn (GameMatch $m) => $m->getPhase() === MatchPhase::Group
        ));
        $done = count(array_filter(
            $groupMatches,
            static fn (GameMatch $m) => $m->getStatus() === MatchStatus::Done && null !== $m->getWinner()
        ));
        $total = count($groupMatches);

        return [
            'total' => $total,
            'done' => $done,
            'complete' => $total > 0 && $done === $total,
        ];
    }
}
