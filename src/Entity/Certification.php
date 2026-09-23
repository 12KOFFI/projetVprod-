<?php

namespace App\Entity;

use App\Repository\CertificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Certification visée par un candidat au terme de son parcours VAE.
 *
 * C'est le diplôme réellement délivré — « CAP Boulangerie-Pâtisserie », et non
 * le simple type « CAP ». Le référentiel officiel en compte 45, réparties entre
 * les deux types reconnus par le dispositif : CQP et CAP.
 *
 * Une certification appartient à UN métier : « CAP Maçonnerie » relève du
 * maçon, et de lui seul. En revanche, c'est le couple (centre, métier) qui
 * décide de l'offrir ou non — un même métier peut ne pas préparer partout aux
 * mêmes certifications. Ce rattachement à l'offre est porté par la relation
 * ManyToMany de CentreMetier, et non par cette entité.
 */
#[ORM\Entity(repositoryClass: CertificationRepository::class)]
#[ORM\Table(name: 'certification')]
#[ORM\UniqueConstraint(name: 'uk_certification_libelle', columns: ['libelle'])]
#[ORM\Index(name: 'idx_certification_metier', columns: ['metier_id'])]
class Certification
{
    public const TYPE_CAP = 'CAP';
    public const TYPE_CQP = 'CQP';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $libelle = null;

    /**
     * Type de certification, déduit du préfixe du libellé mais stocké : les
     * statistiques et les filtres en ont besoin sans avoir à analyser le texte.
     */
    #[ORM\Column(length: 10)]
    private ?string $type = null;

    #[ORM\ManyToOne(targetEntity: Metier::class)]
    #[ORM\JoinColumn(name: 'metier_id', referencedColumnName: 'id', nullable: false)]
    private ?Metier $metier = null;

    /**
     * Une certification retirée du référentiel n'est jamais supprimée : elle
     * reste citée par les dossiers déjà déposés. Elle cesse simplement d'être
     * proposée, comme le fait déjà Metier::$statut.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $creation = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $modification = null;

    public function __construct()
    {
        $this->creation = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): self
    {
        $this->libelle = $libelle;

        // Le type suit toujours le libellé : les laisser diverger produirait un
        // « CAP … » classé en CQP, invisible à la lecture mais faux en base.
        if ($libelle !== null) {
            $this->type = str_starts_with(strtoupper($libelle), self::TYPE_CAP)
                ? self::TYPE_CAP
                : self::TYPE_CQP;
        }

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getMetier(): ?Metier
    {
        return $this->metier;
    }

    public function setMetier(?Metier $metier): self
    {
        $this->metier = $metier;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

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

    public function __toString(): string
    {
        return (string) $this->libelle;
    }
}
