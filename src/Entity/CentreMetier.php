<?php

namespace App\Entity;

use App\Repository\CentreMetierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CentreMetierRepository::class)]
#[ORM\Table(name: 'centre_metier')]
#[ORM\UniqueConstraint(name: 'uk_centre_metier', columns: ['centre_id', 'metier_id'])]
#[ORM\HasLifecycleCallbacks]
class CentreMetier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Centre::class)]
    #[ORM\JoinColumn(name: 'centre_id', referencedColumnName: 'id', nullable: false)]
    private ?Centre $centre = null;

    #[ORM\ManyToOne(targetEntity: Metier::class)]
    #[ORM\JoinColumn(name: 'metier_id', referencedColumnName: 'id', nullable: false)]
    private ?Metier $metier = null;

    #[ORM\Column]
    private ?int $nbrplace = null;

    /**
     * Certifications préparées par ce centre pour ce métier.
     *
     * C'est ici que vit l'offre réelle : un même métier ne prépare pas partout
     * aux mêmes certifications, d'où un rattachement au couple plutôt qu'au
     * seul métier. Le chargement reste paresseux, la liste n'étant consultée
     * qu'au dépôt d'un dossier et dans l'écran d'administration de l'offre.
     *
     * @var Collection<int, Certification>
     */
    #[ORM\ManyToMany(targetEntity: Certification::class)]
    #[ORM\JoinTable(name: 'centre_metier_certification')]
    #[ORM\JoinColumn(name: 'centre_metier_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'certification_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $certifications;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $creation = null;

    public function __construct()
    {
        $this->creation = new \DateTime();
        $this->certifications = new ArrayCollection();
    }

    /**
     * @return Collection<int, Certification>
     */
    public function getCertifications(): Collection
    {
        return $this->certifications;
    }

    public function addCertification(Certification $certification): self
    {
        if (!$this->certifications->contains($certification)) {
            $this->certifications->add($certification);
        }

        return $this;
    }

    public function removeCertification(Certification $certification): self
    {
        $this->certifications->removeElement($certification);

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCentre(): ?Centre
    {
        return $this->centre;
    }

    public function setCentre(?Centre $centre): self
    {
        $this->centre = $centre;
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

    public function getNbrplace(): ?int
    {
        return $this->nbrplace;
    }

    public function setNbrplace(int $nbrplace): self
    {
        $this->nbrplace = $nbrplace;
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
}
