<?php

namespace App\Entity;

use App\Enum\StatutImport;
use App\Enum\TypeImportJury;
use App\Repository\ImportJuryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'un import de décisions du jury central.
 *
 * L'entité ne pointe vers aucune candidature : le lien se lit dans
 * HistoriqueStatut, dont le motif cite le fichier d'origine. Une relation
 * directe coûterait une table d'association pour une traçabilité déjà acquise.
 */
#[ORM\Entity(repositoryClass: ImportJuryRepository::class)]
#[ORM\Table(name: 'import_jury')]
#[ORM\Index(name: 'idx_import_jury_type', columns: ['type', 'creation'])]
class ImportJury
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $type = null;

    #[ORM\Column(length: 255)]
    private ?string $nomFichier = null;

    /** Chemin du fichier conservé sous var/imports/ pour l'audit (R6.10). */
    #[ORM\Column(length: 255)]
    private ?string $cheminFichier = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $lignesTotal = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $lignesTraitees = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $lignesIgnorees = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $lignesErreur = 0;

    /**
     * Détail ligne par ligne, tel que l'écran de rapport l'affiche.
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rapport = null;

    #[ORM\Column(length: 20)]
    private ?string $statut = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'auteur_id', referencedColumnName: 'id', nullable: false)]
    private ?User $auteur = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $creation = null;

    public function __construct()
    {
        $this->creation = new \DateTime();
        $this->statut = StatutImport::EN_COURS->value;
    }

    public function getId(): ?int { return $this->id; }

    public function getType(): TypeImportJury { return TypeImportJury::from((string) $this->type); }
    public function setType(TypeImportJury $type): self { $this->type = $type->value; return $this; }

    public function getNomFichier(): ?string { return $this->nomFichier; }
    public function setNomFichier(string $nom): self { $this->nomFichier = $nom; return $this; }

    public function getCheminFichier(): ?string { return $this->cheminFichier; }
    public function setCheminFichier(string $chemin): self { $this->cheminFichier = $chemin; return $this; }

    public function getLignesTotal(): int { return $this->lignesTotal; }
    public function setLignesTotal(int $lignes): self { $this->lignesTotal = $lignes; return $this; }

    public function getLignesTraitees(): int { return $this->lignesTraitees; }
    public function setLignesTraitees(int $lignes): self { $this->lignesTraitees = $lignes; return $this; }

    public function getLignesIgnorees(): int { return $this->lignesIgnorees; }
    public function setLignesIgnorees(int $lignes): self { $this->lignesIgnorees = $lignes; return $this; }

    public function getLignesErreur(): int { return $this->lignesErreur; }
    public function setLignesErreur(int $lignes): self { $this->lignesErreur = $lignes; return $this; }

    /** @return list<array<string, mixed>>|null */
    public function getRapport(): ?array { return $this->rapport; }

    /** @param list<array<string, mixed>>|null $rapport */
    public function setRapport(?array $rapport): self { $this->rapport = $rapport; return $this; }

    public function getStatut(): StatutImport { return StatutImport::from((string) $this->statut); }
    public function setStatut(StatutImport $statut): self { $this->statut = $statut->value; return $this; }

    public function getAuteur(): ?User { return $this->auteur; }
    public function setAuteur(?User $auteur): self { $this->auteur = $auteur; return $this; }

    public function getCreation(): ?\DateTimeInterface { return $this->creation; }
    public function setCreation(\DateTimeInterface $creation): self { $this->creation = $creation; return $this; }

    /**
     * Lignes du rapport en anomalie, seules affichées en détail : sur un import
     * de plusieurs centaines de lignes, les succès n'apprennent rien.
     *
     * @return list<array<string, mixed>>
     */
    public function getLignesEnAnomalie(): array
    {
        return array_values(array_filter(
            $this->rapport ?? [],
            static fn (array $ligne): bool => ($ligne['issue'] ?? '') !== 'traitee'
        ));
    }
}
