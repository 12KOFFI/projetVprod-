<?php

namespace App\Form;

use App\Dto\CandidatureDepotDto;
use App\Entity\Centre;
use App\Entity\Certification;
use App\Entity\Metier;
use App\Form\Type\TelephoneType;
use App\Referentiel\ProfilProfessionnel;
use App\Repository\CentreRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

/**
 * Formulaire de dépôt et de modification d'un dossier (E3.2, E3.5, E3.7, E3.8).
 *
 * Aucun champ d'instruction n'y figure : avis de recevabilité, statuts et
 * décisions de jury ne sont pas constructibles depuis ce formulaire, ce qui
 * rend la règle R3.11 structurelle plutôt que cosmétique.
 */
class CandidatureDepotType extends AbstractType
{
    /** Pièces acceptées, conformément à la règle métier R3.9. */
    private const TYPES_ACCEPTES = ['image/jpeg', 'image/png', 'application/pdf'];
    private const TAILLE_MAX = '2M';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $centreImpose = $options['centre_impose'];

        // Inscription assistée : le centre est celui de l'agent et n'est pas
        // négociable. Ne pas afficher le champ évite qu'une valeur postée ne
        // soit même considérée (le service le réimpose de toute façon, R3.7).
        if ($centreImpose === null) {
            $builder->add('centre', EntityType::class, [
                'class' => Centre::class,
                // « Nom · Ville » : la liste avec recherche affiche la ville en
                // second et la trouve à la frappe.
                'choice_label' => static fn (Centre $centre) => $centre->getNom()
                    . ($centre->getLocalite() ? ' · ' . $centre->getLocalite()->getLibelle() : ''),
                'label' => 'Centre de dépôt',
                'placeholder' => 'Sélectionnez un centre',
                'query_builder' => static fn (CentreRepository $repository) => $repository
                    ->createQueryBuilder('c')
                    ->addSelect('l')
                    ->leftJoin('c.localite', 'l')
                    ->orderBy('c.nom', 'ASC'),
            ]);
        }

        $builder
            ->add('metier', EntityType::class, [
                'class' => Metier::class,
                'choice_label' => 'libelle',
                'label' => 'Métier visé',
                'placeholder' => 'Sélectionnez d\'abord un centre',
                'choices' => $options['metiers_disponibles'],
            ])
            // Le minimum (5 ans) est bloqué à la saisie comme au serveur.
            ->add('nbAnneesExperience', IntegerType::class, [
                'label' => "Années d'expérience dans le métier",
                'attr' => ['min' => ProfilProfessionnel::ANNEES_CQP, 'max' => 60, 'inputmode' => 'numeric'],
            ])
            // La liste dépend du couple (centre, métier) : elle est donc vide au
            // premier affichage et peuplée en JavaScript après le choix du
            // métier, comme l'est déjà le champ « Métier visé » après le centre.
            // data-type sert au filtre CQP / CAP selon l'expérience. Le serveur
            // revérifie le choix (CertificationOfferte, et le DTO pour le type).
            ->add('certification', EntityType::class, [
                'class' => Certification::class,
                'choice_label' => 'libelle',
                'choice_attr' => static fn (Certification $certification) => ['data-type' => $certification->getType()],
                'label' => 'Diplôme de certification visé',
                'placeholder' => 'Sélectionnez d\'abord un métier',
                'choices' => $options['certifications_disponibles'],
            ])
            ->add('situationPro', ChoiceType::class, [
                'label' => 'Situation professionnelle',
                'placeholder' => 'Sélectionnez',
                'choices' => ProfilProfessionnel::choix(ProfilProfessionnel::SITUATIONS),
            ])
            // Les précisions « Autre » ne s'affichent qu'avec ce choix ; elles
            // sont alors obligatoires (contraintes When du DTO).
            ->add('situationProPrecision', TextType::class, [
                'label' => 'Précisez la situation',
                'required' => false,
                'attr' => ['maxlength' => 255, 'placeholder' => 'Ex. : apprenti, aide familial…'],
            ])
            ->add('diplome', ChoiceType::class, [
                'label' => 'Diplôme académique obtenu(e)',
                'placeholder' => 'Aucun',
                'required' => false,
                'choices' => ProfilProfessionnel::choix(ProfilProfessionnel::DIPLOMES),
            ])
            ->add('diplomePrecision', TextType::class, [
                'label' => 'Précisez le diplôme',
                'required' => false,
                'attr' => ['maxlength' => 255, 'placeholder' => 'Ex. : CAP d\'un autre métier, BT…'],
            ])
            ->add('nomEntreprise', TextType::class, [
                'label' => 'Entreprise, organisme ou administration d\'attache',
                'required' => false,
            ])
            ->add('refentreprise', TextType::class, [
                'label' => 'Références de l\'entreprise',
                'required' => false,
                
            ])
            ->add('lieuExercice', TextType::class, [
                'label' => 'Lieu d\'exercice (Région, Département, Sous-préfecture)',
                'required' => false,
                'attr' => ['placeholder' => 'Ex. : Gbêkê, Bouaké, Bouaké'],
            ])
            ->add('fonction', TextType::class, [
                'label' => 'Poste de travail',
                'required' => false,
            ])
            ->add('refContrat', TextType::class, [
                'label' => 'Référence du contrat de travail',
                'required' => false,
                
            ])
            ->add('directionService', TextType::class, [
                'label' => 'Direction ou service',
                'required' => false,
            ])
            ->add('contactemployeur', TelephoneType::class, [
                'label' => 'Contact de l\'employeur',
                'required' => false,
            ])
            ->add('langue', ChoiceType::class, [
                'label' => 'Langue d\'évaluation',
                'placeholder' => 'Sélectionnez',
                'required' => false,
                'choices' => ProfilProfessionnel::choix(ProfilProfessionnel::LANGUES),
            ])
            ->add('preciserlangue', TextType::class, [
                'label' => 'Préciser la langue',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ]);

        // Le justificatif d'expérience n'existe dans le formulaire que pour le
        // conseiller VAE : le candidat et l'agent d'accueil ne peuvent ni le
        // voir ni le poster.
        $documents = CandidatureDepotDto::documentsObligatoires()
            + ($options['pieces_conseiller'] ? CandidatureDepotDto::documentsConseiller() : []);

        foreach ($documents as $champ => $libelle) {
            $builder->add($champ, FileType::class, [
                'label' => $libelle,
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File(
                        maxSize: self::TAILLE_MAX,
                        mimeTypes: self::TYPES_ACCEPTES,
                        mimeTypesMessage: 'Formats acceptés : JPG, PNG ou PDF.',
                        maxSizeMessage: 'Le fichier ne doit pas dépasser {{ limit }} {{ suffix }}.',
                    ),
                ],
            ]);
        }

    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CandidatureDepotDto::class,
            'csrf_token_id' => 'candidature_depot',
            'centre_impose' => null,
            'metiers_disponibles' => [],
            'certifications_disponibles' => [],
            'pieces_conseiller' => false,
            'post_max_size_message' => 'Le poids total des pièces jointes dépasse la limite autorisée par le serveur ({{ max }}). Réduisez la taille de vos fichiers ou envoyez-les en plusieurs fois.',
        ]);

        $resolver->setAllowedTypes('centre_impose', ['null', Centre::class]);
        $resolver->setAllowedTypes('metiers_disponibles', 'array');
        $resolver->setAllowedTypes('certifications_disponibles', 'array');
        $resolver->setAllowedTypes('pieces_conseiller', 'bool');
    }
}
