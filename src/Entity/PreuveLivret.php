<?php

namespace App\Entity;

use App\Repository\PreuveLivretRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pièce du livret de preuves d'un candidat (étape 5 du parcours).
 *
 * Le candidat et son accompagnateur y déposent les preuves de son expérience
 * (photos de réalisations, attestations, factures…) présentées au jury.
 * Le fichier est rangé avec les autres pièces, hors du dossier public :
 * stockage/pieces/{numéro}/livret/, servi par PieceController::preuve().
 */
#[ORM\Entity(repositoryClass: PreuveLivretRepository::class)]
#[ORM\Table(name: 'preuve_livret')]
#[ORM\Index(columns: ['candidature_id'], name: 'idx_preuve_livret_candidature')]
class PreuveLivret
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Candidature::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Candidature $candidature = null;

    #[ORM\Column(length: 150)]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Nom du fichier sur le disque (aléatoire, jamais celui envoyé). */
    #[ORM\Column(length: 255)]
    private ?string $fichier = null;

    /** Nom d'origine, affiché seulement. */
    #[ORM\Column(length: 255)]
    private ?string $nomOriginal = null;

    #[ORM\Column]
    private int $taille = 0;

    /** Auteur du dépôt : le candidat ou son accompagnateur (traçabilité). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $deposePar = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $creation = null;

    public function __construct()
    {
        $this->creation = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getCandidature(): ?Candidature { return $this->candidature; }
    public function setCandidature(?Candidature $candidature): self { $this->candidature = $candidature; return $this; }
    public function getTitre(): ?string { return $this->titre; }
    public function setTitre(string $titre): self { $this->titre = $titre; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getFichier(): ?string { return $this->fichier; }
    public function setFichier(string $fichier): self { $this->fichier = $fichier; return $this; }
    public function getNomOriginal(): ?string { return $this->nomOriginal; }
    public function setNomOriginal(string $nomOriginal): self { $this->nomOriginal = $nomOriginal; return $this; }
    public function getTaille(): int { return $this->taille; }
    public function setTaille(int $taille): self { $this->taille = $taille; return $this; }
    public function getDeposePar(): ?User { return $this->deposePar; }
    public function setDeposePar(?User $deposePar): self { $this->deposePar = $deposePar; return $this; }
    public function getCreation(): ?\DateTimeInterface { return $this->creation; }

    public function estPdf(): bool
    {
        return str_ends_with(strtolower((string) $this->fichier), '.pdf');
    }
}
