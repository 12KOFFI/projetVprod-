<?php

namespace App\Entity;

use App\Enum\StatutEtude;
use App\Enum\StatutRecevabilite;
use App\Repository\CandidatureRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CandidatureRepository::class)]
#[ORM\Table(name: 'candidature')]
#[ORM\HasLifecycleCallbacks]
class Candidature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $numero = null;

    #[ORM\ManyToOne(targetEntity: Centre::class)]
    #[ORM\JoinColumn(name: 'centre_id', referencedColumnName: 'id', nullable: false)]
    private ?Centre $centre = null;

    #[ORM\ManyToOne(targetEntity: Metier::class)]
    #[ORM\JoinColumn(name: 'metier_id', referencedColumnName: 'id', nullable: false)]
    private ?Metier $metier = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $diplomedemande = null;

    /** Diplôme académique déjà obtenu (CEPE, BEPC, BAC…). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $diplome = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $situationPro = null;

    #[ORM\Column(options: ['default' => 0])]
    private ?int $nbAnneesExperience = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomEntreprise = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $refentreprise = null;

    /** Lieu d'exercice : région, département, sous-préfecture. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lieuExercice = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $directionService = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fonction = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $refContrat = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titrepro = null;

    #[ORM\Column(length: 22, nullable: true)]
    private ?string $contactemployeur = null;

    #[ORM\Column(nullable: true)]
    private ?int $apprentirecute = null;

    #[ORM\Column(nullable: true)]
    private ?int $apprentiforme = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $langue = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $preciserlangue = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fphoto = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fpiece = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fextrait = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fexperiencepro = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fcmu = null;

    #[ORM\Column(nullable: true, options: ['comment' => 'statut de recevabilité (0 : en_attente, 1 : non_recevable, 2 : recevable)'])]
    private ?int $recStatut = null;

    #[ORM\Column(nullable: true, options: ['comment' => 'statut de l\'étude du dossier (0 : en_attente, 1 : REFUSE, 2 : ACCEPTE)'])]
    private ?int $etuStatut = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $etuCom = null;

    #[ORM\Column(nullable: true)]
    private ?int $accStatut = null;

    /** Décision d'éligibilité du jury central (1 : non éligible, 2 : éligible). */
    #[ORM\Column(nullable: true)]
    private ?int $eligStatut = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $entdate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $entlieu = null;

    /** Décision d'admissibilité uniquement (1 : non admissible, 2 : admissible). */
    #[ORM\Column(nullable: true)]
    private ?int $resultat = null;

    /** Décision d'admission définitive du jury central (1 : non admis, 2 : admis). */
    #[ORM\Column(nullable: true)]
    private ?int $admis = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $creation = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $modification = null;

    /**
     * Le candidat propriétaire du dossier — l'unique identifiant de propriété.
     *
     * Lors d'une inscription assistée, l'agent d'accueil qui a créé le dossier
     * est tracé séparément par $agentAccueil : $user reste toujours le candidat,
     * jamais l'agent.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'userupdate_id', referencedColumnName: 'id', nullable: true)]
    private ?User $userUpdate = null;

    /** Agent d'accueil ayant réalisé l'inscription assistée (traçabilité, spec 5.2). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'agent_accueil_id', referencedColumnName: 'id', nullable: true)]
    private ?User $agentAccueil = null;

    /** Conseiller VAE en charge du dossier. L'affectation reste flexible (spec 5.3). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'conseiller_id', referencedColumnName: 'id', nullable: true)]
    private ?User $conseiller = null;

    /** Accompagnateur effectivement affecté, après paiement des frais d'accompagnement. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'accompagnateur_id', referencedColumnName: 'id', nullable: true)]
    private ?User $accompagnateur = null;

    /**
     * Règlements du candidat. Le statut global n'est stocké nulle part : il se
     * calcule à partir des décisions et du règlement des frais de dossier
     * (App\Service\Candidature\CandidatureStatusResolver).
     *
     * @var Collection<int, Paiement>
     */
    #[ORM\OneToMany(mappedBy: 'candidature', targetEntity: Paiement::class)]
    private Collection $paiements;

    /** Date à laquelle l'étude du dossier a été enregistrée par le conseiller. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $etuDate = null;

    /** Date à laquelle la décision de recevabilité a été enregistrée par le conseiller. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $recDate = null;

    public function __construct()
    {
        $this->creation = new \DateTime();
        $this->paiements = new ArrayCollection();
    }

    // Getters and Setters...
    public function getId(): ?int { return $this->id; }
    public function getNumero(): ?string { return $this->numero; }
    public function setNumero(string $numero): self { $this->numero = $numero; return $this; }
    public function getCentre(): ?Centre { return $this->centre; }
    public function setCentre(?Centre $centre): self { $this->centre = $centre; return $this; }
    public function getMetier(): ?Metier { return $this->metier; }
    public function setMetier(?Metier $metier): self { $this->metier = $metier; return $this; }
    public function getDiplomedemande(): ?string { return $this->diplomedemande; }
    public function setDiplomedemande(?string $diplomedemande): self { $this->diplomedemande = $diplomedemande; return $this; }
    public function getDiplome(): ?string { return $this->diplome; }
    public function setDiplome(?string $diplome): self { $this->diplome = $diplome; return $this; }
    public function getSituationPro(): ?string { return $this->situationPro; }
    public function setSituationPro(?string $situationPro): self { $this->situationPro = $situationPro; return $this; }
    public function getNbAnneesExperience(): ?int { return $this->nbAnneesExperience; }
    public function setNbAnneesExperience(int $nbAnneesExperience): self { $this->nbAnneesExperience = $nbAnneesExperience; return $this; }
    public function getNomEntreprise(): ?string { return $this->nomEntreprise; }
    public function setNomEntreprise(?string $nomEntreprise): self { $this->nomEntreprise = $nomEntreprise; return $this; }
    public function getRefentreprise(): ?string { return $this->refentreprise; }
    public function setRefentreprise(?string $refentreprise): self { $this->refentreprise = $refentreprise; return $this; }
    public function getLieuExercice(): ?string { return $this->lieuExercice; }
    public function setLieuExercice(?string $lieuExercice): self { $this->lieuExercice = $lieuExercice; return $this; }
    public function getDirectionService(): ?string { return $this->directionService; }
    public function setDirectionService(?string $directionService): self { $this->directionService = $directionService; return $this; }
    public function getFonction(): ?string { return $this->fonction; }
    public function setFonction(?string $fonction): self { $this->fonction = $fonction; return $this; }
    public function getRefContrat(): ?string { return $this->refContrat; }
    public function setRefContrat(?string $refContrat): self { $this->refContrat = $refContrat; return $this; }
    public function getTitrepro(): ?string { return $this->titrepro; }
    public function setTitrepro(?string $titrepro): self { $this->titrepro = $titrepro; return $this; }
    public function getContactemployeur(): ?string { return $this->contactemployeur; }
    public function setContactemployeur(?string $contactemployeur): self { $this->contactemployeur = $contactemployeur; return $this; }
    public function getApprentirecute(): ?int { return $this->apprentirecute; }
    public function setApprentirecute(?int $apprentirecute): self { $this->apprentirecute = $apprentirecute; return $this; }
    public function getApprentiforme(): ?int { return $this->apprentiforme; }
    public function setApprentiforme(?int $apprentiforme): self { $this->apprentiforme = $apprentiforme; return $this; }
    public function getLangue(): ?string { return $this->langue; }
    public function setLangue(?string $langue): self { $this->langue = $langue; return $this; }
    public function getPreciserlangue(): ?string { return $this->preciserlangue; }
    public function setPreciserlangue(?string $preciserlangue): self { $this->preciserlangue = $preciserlangue; return $this; }
    public function getFphoto(): ?string { return $this->fphoto; }
    public function setFphoto(?string $fphoto): self { $this->fphoto = $fphoto; return $this; }
    public function getFpiece(): ?string { return $this->fpiece; }
    public function setFpiece(?string $fpiece): self { $this->fpiece = $fpiece; return $this; }
    public function getFextrait(): ?string { return $this->fextrait; }
    public function setFextrait(?string $fextrait): self { $this->fextrait = $fextrait; return $this; }
    public function getFexperiencepro(): ?string { return $this->fexperiencepro; }
    public function setFexperiencepro(?string $fexperiencepro): self { $this->fexperiencepro = $fexperiencepro; return $this; }
    public function getFcmu(): ?string { return $this->fcmu; }
    public function setFcmu(?string $fcmu): self { $this->fcmu = $fcmu; return $this; }
    public function getRecStatut(): ?int { return $this->recStatut; }
    public function setRecStatut(?int $recStatut): self { $this->recStatut = $recStatut; return $this; }
    public function getEtuStatut(): ?int { return $this->etuStatut; }
    public function setEtuStatut(?int $etuStatut): self { $this->etuStatut = $etuStatut; return $this; }
    public function getEtuCom(): ?string { return $this->etuCom; }
    public function setEtuCom(?string $etuCom): self { $this->etuCom = $etuCom; return $this; }
    public function getAccStatut(): ?int { return $this->accStatut; }
    public function setAccStatut(?int $accStatut): self { $this->accStatut = $accStatut; return $this; }
    public function getEligStatut(): ?int { return $this->eligStatut; }
    public function setEligStatut(?int $eligStatut): self { $this->eligStatut = $eligStatut; return $this; }
    public function getEntdate(): ?\DateTimeInterface { return $this->entdate; }
    public function setEntdate(?\DateTimeInterface $entdate): self { $this->entdate = $entdate; return $this; }
    public function getEntlieu(): ?string { return $this->entlieu; }
    public function setEntlieu(?string $entlieu): self { $this->entlieu = $entlieu; return $this; }
    public function getResultat(): ?int { return $this->resultat; }
    public function setResultat(?int $resultat): self { $this->resultat = $resultat; return $this; }
    public function getAdmis(): ?int { return $this->admis; }
    public function setAdmis(?int $admis): self { $this->admis = $admis; return $this; }

    /** @return Collection<int, Paiement> */
    public function getPaiements(): Collection { return $this->paiements; }
    public function getCreation(): ?\DateTimeInterface { return $this->creation; }
    public function setCreation(\DateTimeInterface $creation): self { $this->creation = $creation; return $this; }
    public function getModification(): ?\DateTimeInterface { return $this->modification; }
    public function setModification(?\DateTimeInterface $modification): self { $this->modification = $modification; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    /** Dernier utilisateur ayant modifié le dossier. */
    public function getUserUpdate(): ?User { return $this->userUpdate; }
    public function setUserUpdate(?User $userUpdate): self { $this->userUpdate = $userUpdate; return $this; }

    public function getAgentAccueil(): ?User { return $this->agentAccueil; }
    public function setAgentAccueil(?User $agentAccueil): self { $this->agentAccueil = $agentAccueil; return $this; }
    public function getConseiller(): ?User { return $this->conseiller; }
    public function setConseiller(?User $conseiller): self { $this->conseiller = $conseiller; return $this; }
    public function getAccompagnateur(): ?User { return $this->accompagnateur; }
    public function setAccompagnateur(?User $accompagnateur): self { $this->accompagnateur = $accompagnateur; return $this; }

    public function getEtuDate(): ?\DateTimeInterface { return $this->etuDate; }
    public function setEtuDate(?\DateTimeInterface $date): self { $this->etuDate = $date; return $this; }

    public function getRecDate(): ?\DateTimeInterface { return $this->recDate; }
    public function setRecDate(?\DateTimeInterface $date): self { $this->recDate = $date; return $this; }

    /** Lecture seule : résultat de l'étude, sans lien avec le statut officiel. */
    public function getResultatEtude(): ?StatutEtude
    {
        return $this->etuStatut !== null ? StatutEtude::tryFrom($this->etuStatut) : null;
    }

    /** Lecture seule : résultat de la recevabilité, sans lien avec le statut officiel. */
    public function getResultatRecevabilite(): ?StatutRecevabilite
    {
        return $this->recStatut !== null ? StatutRecevabilite::tryFrom($this->recStatut) : null;
    }

    #[ORM\PreUpdate]
    public function setModificationValue(): void
    {
        $this->modification = new \DateTime();
    }
}
