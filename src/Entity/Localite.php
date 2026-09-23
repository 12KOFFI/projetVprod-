<?php

namespace App\Entity;

use App\Repository\LocaliteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LocaliteRepository::class)]
#[ORM\Table(name: 'localite')]
#[ORM\HasLifecycleCallbacks]
class Localite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DirectionRegionale::class)]
    #[ORM\JoinColumn(name: 'direction_regionale_id', referencedColumnName: 'id', nullable: false)]
    private ?DirectionRegionale $directionRegionale = null;

    #[ORM\Column(length: 150)]
    private ?string $libelle = null;

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

    public function getDirectionRegionale(): ?DirectionRegionale
    {
        return $this->directionRegionale;
    }

    public function setDirectionRegionale(?DirectionRegionale $directionRegionale): self
    {
        $this->directionRegionale = $directionRegionale;
        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;
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

    #[ORM\PreUpdate]
    public function setModificationValue(): void
    {
        $this->modification = new \DateTime();
    }
}
