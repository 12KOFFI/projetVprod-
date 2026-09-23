<?php

namespace App\Entity;

use App\Enum\StatutPaiement;
use App\Repository\TransactionPaiementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des échanges avec la passerelle de paiement.
 *
 * Un Paiement porte l'état courant d'un frais ; chaque tentative, chaque
 * confirmation et chaque remboursement produit ici une ligne conservant la
 * réponse brute du fournisseur. C'est la source de vérité en cas de litige
 * avec le Trésor, et la trace exigée par la traçabilité (partie D.5).
 */
#[ORM\Entity(repositoryClass: TransactionPaiementRepository::class)]
#[ORM\Table(name: 'transaction_paiement')]
#[ORM\Index(name: 'idx_transaction_paiement', columns: ['paiement_id', 'creation'])]
#[ORM\Index(name: 'idx_transaction_externe', columns: ['identifiant_externe'])]
class TransactionPaiement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Paiement::class)]
    #[ORM\JoinColumn(name: 'paiement_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Paiement $paiement = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $identifiantExterne = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private ?string $montant = null;

    #[ORM\Column(length: 20)]
    private ?string $statut = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $moyenPaiement = null;

    /** Passerelle ayant traité l'opération, ex. « tresor_pay_simulation ». */
    #[ORM\Column(length: 50)]
    private ?string $passerelle = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $codeErreur = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $messageErreur = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $reponseFournisseur = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $adresseIp = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $creation = null;

    public function __construct()
    {
        $this->creation = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getPaiement(): ?Paiement { return $this->paiement; }
    public function setPaiement(?Paiement $paiement): self { $this->paiement = $paiement; return $this; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(string $reference): self { $this->reference = $reference; return $this; }

    public function getIdentifiantExterne(): ?string { return $this->identifiantExterne; }
    public function setIdentifiantExterne(?string $identifiant): self { $this->identifiantExterne = $identifiant; return $this; }

    public function getMontant(): ?string { return $this->montant; }
    public function setMontant(string $montant): self { $this->montant = $montant; return $this; }

    public function getStatut(): StatutPaiement { return StatutPaiement::from((string) $this->statut); }
    public function setStatut(StatutPaiement $statut): self { $this->statut = $statut->value; return $this; }

    public function getMoyenPaiement(): ?string { return $this->moyenPaiement; }
    public function setMoyenPaiement(?string $moyen): self { $this->moyenPaiement = $moyen; return $this; }

    public function getPasserelle(): ?string { return $this->passerelle; }
    public function setPasserelle(string $passerelle): self { $this->passerelle = $passerelle; return $this; }

    public function getCodeErreur(): ?string { return $this->codeErreur; }
    public function setCodeErreur(?string $code): self { $this->codeErreur = $code; return $this; }

    public function getMessageErreur(): ?string { return $this->messageErreur; }
    public function setMessageErreur(?string $message): self { $this->messageErreur = $message; return $this; }

    /** @return array<string, mixed>|null */
    public function getReponseFournisseur(): ?array { return $this->reponseFournisseur; }

    /** @param array<string, mixed>|null $reponse */
    public function setReponseFournisseur(?array $reponse): self { $this->reponseFournisseur = $reponse; return $this; }

    public function getAdresseIp(): ?string { return $this->adresseIp; }
    public function setAdresseIp(?string $ip): self { $this->adresseIp = $ip; return $this; }

    public function getCreation(): ?\DateTimeInterface { return $this->creation; }
    public function setCreation(\DateTimeInterface $creation): self { $this->creation = $creation; return $this; }
}
