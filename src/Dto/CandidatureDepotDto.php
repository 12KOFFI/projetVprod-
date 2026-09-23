<?php

namespace App\Dto;

use App\Entity\Candidature;
use App\Entity\Centre;
use App\Entity\Certification;
use App\Entity\Metier;
use App\Validator\CertificationOfferte;
use App\Validator\MetierOuvertDansCentre;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données du formulaire de dépôt de dossier (écrans E3.2, E3.5, E3.7, E3.8).
 *
 * Le formulaire n'est jamais lié directement à l'entité Candidature : les
 * champs d'instruction (avis de recevabilité, statuts, décisions de jury) ne
 * doivent structurellement pas pouvoir être soumis par le candidat — règle
 * métier R3.11, garantie ici par construction et non par du CSS.
 */
#[MetierOuvertDansCentre]
#[CertificationOfferte]
class CandidatureDepotDto
{
    #[Assert\NotNull(message: 'Le centre est obligatoire.')]
    public ?Centre $centre = null;

    #[Assert\NotNull(message: 'Le métier est obligatoire.')]
    public ?Metier $metier = null;

    #[Assert\NotNull(message: "Le nombre d'années d'expérience est obligatoire.")]
    #[Assert\PositiveOrZero(message: "Le nombre d'années d'expérience ne peut pas être négatif.")]
    #[Assert\LessThanOrEqual(value: 60, message: "Le nombre d'années d'expérience ne peut pas dépasser {{ compared_value }}.")]
    public ?int $nbAnneesExperience = null;

    /**
     * Certification visée — le diplôme réellement préparé, « CAP Boulangerie-
     * Pâtisserie » et non le seul type « CAP ».
     *
     * Le choix appartient au candidat : son expérience n'oriente pas la liste,
     * elle n'est qu'un repère affiché. La cohérence avec le couple (centre,
     * métier) est vérifiée par la contrainte CertificationOfferte.
     */
    #[Assert\NotNull(message: 'Le diplôme visé est obligatoire.')]
    public ?Certification $certification = null;

    #[Assert\NotBlank(message: 'La situation professionnelle est obligatoire.')]
    #[Assert\Length(max: 255)]
    public ?string $situationPro = null;

    /** Diplôme académique déjà obtenu (CEPE, BEPC, BAC…). */
    #[Assert\Length(max: 255)]
    public ?string $diplome = null;

    #[Assert\Length(max: 255)]
    public ?string $titrepro = null;

    #[Assert\Length(max: 255)]
    public ?string $nomEntreprise = null;

    #[Assert\Length(max: 255)]
    public ?string $refentreprise = null;

    #[Assert\Length(max: 255)]
    public ?string $lieuExercice = null;

    #[Assert\Length(max: 255)]
    public ?string $fonction = null;

    #[Assert\Length(max: 255)]
    public ?string $refContrat = null;

    #[Assert\Length(max: 255)]
    public ?string $directionService = null;

    #[Assert\Regex(
        pattern: '/^\d{10}$/',
        message: "Le contact de l'employeur doit contenir 10 chiffres.",
    )]
    public ?string $contactemployeur = null;

    #[Assert\Length(max: 45)]
    public ?string $langue = null;

    #[Assert\Length(max: 100)]
    public ?string $preciserlangue = null;

    /**
     * Documents justificatifs. Les cinq pièces, photo d'identité comprise,
     * sont obligatoires au premier dépôt, mais pas lors d'une
     * modification où un fichier déjà transmis fait foi (règle métier R3.8).
     *
     * @var array<string, UploadedFile|null>
     */
    public array $documents = [];

    /**
     * Pièces exigées au dépôt, avec leur libellé d'affichage.
     *
     * @return array<string, string>
     */
    public static function documentsObligatoires(): array
    {
        return [
            'fextrait' => 'Extrait de naissance',
            'fpiece' => "Pièce d'identité",
            'fexperiencepro' => "Justificatif d'expérience professionnelle",
            'fcmu' => 'Attestation CMU',
            'fphoto' => "Photo d'identité",
        ];
    }

    /**
     * Reconstitue le formulaire depuis un dossier existant.
     *
     * La certification est fournie par l'appelant : le dossier n'en conserve
     * que le libellé, et résoudre l'entité demanderait un accès au repository
     * que ce DTO n'a pas à connaître.
     */
    public static function depuisCandidature(Candidature $candidature, ?Certification $certification = null): self
    {
        $dto = new self();
        $dto->centre = $candidature->getCentre();
        $dto->metier = $candidature->getMetier();
        $dto->nbAnneesExperience = $candidature->getNbAnneesExperience();
        $dto->certification = $certification;
        $dto->situationPro = $candidature->getSituationPro();
        $dto->diplome = $candidature->getDiplome();
        $dto->titrepro = $candidature->getTitrepro();
        $dto->nomEntreprise = $candidature->getNomEntreprise();
        $dto->refentreprise = $candidature->getRefentreprise();
        $dto->lieuExercice = $candidature->getLieuExercice();
        $dto->fonction = $candidature->getFonction();
        $dto->refContrat = $candidature->getRefContrat();
        $dto->directionService = $candidature->getDirectionService();
        $dto->contactemployeur = $candidature->getContactemployeur();
        $dto->langue = $candidature->getLangue();
        $dto->preciserlangue = $candidature->getPreciserlangue();

        return $dto;
    }
}
