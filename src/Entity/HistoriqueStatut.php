<?php

namespace App\Entity;

use App\Enum\StatutCandidature;
use App\Repository\HistoriqueStatutRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des changements de statut d'une candidature.
 *
 * Une ligne est écrite pour chaque transition appliquée par
 * App\Service\Candidature\TransitionCandidature. C'est la source de vérité
 * pour la traçabilité exigée par les règles métier (paiements, décisions,
 * validations, actions administratives).
 */
#[ORM\Entity(repositoryClass: HistoriqueStatutRepository::class)]
#[ORM\Table(name: 'historique_statut')]
#[ORM\Index(name: 'idx_historique_candidature', columns: ['candidature_id', 'creation'])]
class HistoriqueStatut
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Candidature::class)]
    #[ORM\JoinColumn(name: 'candidature_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Candidature $candidature = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $statutAvant = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $statutApres = 0;

    /** Null lorsque la transition est déclenchée par le système (paiement, import). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'auteur_id', referencedColumnName: 'id', nullable: true)]
    private ?User $auteur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motif = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $adresseIp = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $creation = null;

    public function __construct()
    {
        $this->creation = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getCandidature(): ?Candidature { return $this->candidature; }
    public function setCandidature(?Candidature $candidature): self { $this->candidature = $candidature; return $this; }

    public function getStatutAvant(): ?StatutCandidature
    {
        return $this->statutAvant !== null ? StatutCandidature::from($this->statutAvant) : null;
    }

    public function setStatutAvant(?StatutCandidature $statut): self
    {
        $this->statutAvant = $statut?->value;
        return $this;
    }

    public function getStatutApres(): StatutCandidature
    {
        return StatutCandidature::from($this->statutApres);
    }

    public function setStatutApres(StatutCandidature $statut): self
    {
        $this->statutApres = $statut->value;
        return $this;
    }

    public function getAuteur(): ?User { return $this->auteur; }
    public function setAuteur(?User $auteur): self { $this->auteur = $auteur; return $this; }

    public function getMotif(): ?string { return $this->motif; }
    public function setMotif(?string $motif): self { $this->motif = $motif; return $this; }

    public function getCommentaire(): ?string { return $this->commentaire; }
    public function setCommentaire(?string $commentaire): self { $this->commentaire = $commentaire; return $this; }

    public function getAdresseIp(): ?string { return $this->adresseIp; }
    public function setAdresseIp(?string $adresseIp): self { $this->adresseIp = $adresseIp; return $this; }

    public function getCreation(): ?\DateTimeInterface { return $this->creation; }
    public function setCreation(\DateTimeInterface $creation): self { $this->creation = $creation; return $this; }
}
