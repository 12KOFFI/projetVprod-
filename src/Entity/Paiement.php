<?php

namespace App\Entity;

use App\Enum\MoyenPaiement;
use App\Enum\StatutPaiement;
use App\Enum\TypeFrais;
use App\Repository\PaiementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaiementRepository::class)]
#[ORM\Table(name: 'paiement')]
#[ORM\HasLifecycleCallbacks]
class Paiement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Candidature::class, inversedBy: 'paiements')]
    #[ORM\JoinColumn(name: 'candidature_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Candidature $candidature = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private ?string $typeFrais = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private ?string $montant = null;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => 'en_attente'])]
    private ?string $statutPaiement = 'en_attente';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $referencePaiement = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateEcheance = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $datePaiement = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $creation = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $modification = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $moyenPaiement = null;

    /** Identifiant de la transaction chez le fournisseur, clé de rapprochement. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $identifiantExterne = null;

    #[ORM\Column(length: 3, options: ['default' => 'XOF'])]
    private string $devise = 'XOF';

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateInitiation = null;

    /** Nombre de règlements tentés, y compris ceux qui ont échoué. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $tentatives = 0;

    public function __construct()
    {
        $this->creation = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCandidature(): ?Candidature
    {
        return $this->candidature;
    }

    public function setCandidature(?Candidature $candidature): self
    {
        $this->candidature = $candidature;

        // Garde la collection inverse à jour dans la requête courante : le
        // statut calculé doit voir un règlement dès sa création.
        if ($candidature !== null && !$candidature->getPaiements()->contains($this)) {
            $candidature->getPaiements()->add($this);
        }

        return $this;
    }

    public function getTypeFrais(): ?string
    {
        return $this->typeFrais;
    }

    public function setTypeFrais(string $typeFrais): self
    {
        $this->typeFrais = $typeFrais;
        return $this;
    }

    public function getMontant(): ?string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): self
    {
        $this->montant = $montant;
        return $this;
    }

    public function getStatutPaiement(): ?string
    {
        return $this->statutPaiement;
    }

    public function setStatutPaiement(string $statutPaiement): self
    {
        $this->statutPaiement = $statutPaiement;
        return $this;
    }

    public function getReferencePaiement(): ?string
    {
        return $this->referencePaiement;
    }

    public function setReferencePaiement(?string $referencePaiement): self
    {
        $this->referencePaiement = $referencePaiement;
        return $this;
    }

    public function getDateEcheance(): ?\DateTimeInterface
    {
        return $this->dateEcheance;
    }

    public function setDateEcheance(?\DateTimeInterface $dateEcheance): self
    {
        $this->dateEcheance = $dateEcheance;
        return $this;
    }

    public function getDatePaiement(): ?\DateTimeInterface
    {
        return $this->datePaiement;
    }

    public function setDatePaiement(?\DateTimeInterface $datePaiement): self
    {
        $this->datePaiement = $datePaiement;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getCreation(): ?\DateTimeInterface
    {
        return $this->creation;
    }

    public function setCreation(\DateTimeInterface $creation): self
    {
        $this->creation = $creation;
        return $this;
    }

    public function getModification(): ?\DateTimeInterface
    {
        return $this->modification;
    }

    public function setModification(?\DateTimeInterface $modification): self
    {
        $this->modification = $modification;
        return $this;
    }

    public function getMoyenPaiement(): ?MoyenPaiement
    {
        return $this->moyenPaiement !== null ? MoyenPaiement::tryFrom($this->moyenPaiement) : null;
    }

    public function setMoyenPaiement(?MoyenPaiement $moyen): self
    {
        $this->moyenPaiement = $moyen?->value;
        return $this;
    }

    public function getIdentifiantExterne(): ?string { return $this->identifiantExterne; }
    public function setIdentifiantExterne(?string $identifiant): self { $this->identifiantExterne = $identifiant; return $this; }

    public function getDevise(): string { return $this->devise; }
    public function setDevise(string $devise): self { $this->devise = $devise; return $this; }

    public function getDateInitiation(): ?\DateTimeInterface { return $this->dateInitiation; }
    public function setDateInitiation(?\DateTimeInterface $date): self { $this->dateInitiation = $date; return $this; }

    public function getTentatives(): int { return $this->tentatives; }
    public function setTentatives(int $tentatives): self { $this->tentatives = $tentatives; return $this; }
    public function incrementerTentatives(): self { ++$this->tentatives; return $this; }

    /**
     * Accesseurs typés : le type de frais et le statut sont des vocabulaires
     * fermés, les manipuler comme des chaînes exposerait à des fautes de frappe.
     */
    public function getType(): TypeFrais
    {
        return TypeFrais::from((string) $this->typeFrais);
    }

    public function setType(TypeFrais $type): self
    {
        $this->typeFrais = $type->value;
        return $this;
    }

    public function getStatut(): StatutPaiement
    {
        return StatutPaiement::from((string) $this->statutPaiement);
    }

    public function setStatut(StatutPaiement $statut): self
    {
        $this->statutPaiement = $statut->value;
        return $this;
    }

    public function estReussi(): bool
    {
        return $this->getStatut() === StatutPaiement::REUSSI;
    }

    #[ORM\PreUpdate]
    public function setModificationValue(): void
    {
        $this->modification = new \DateTime();
    }
}
