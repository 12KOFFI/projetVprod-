<?php

namespace App\Form;

use App\Dto\CandidatureDepotDto;
use App\Entity\Centre;
use App\Entity\Certification;
use App\Entity\Metier;
use App\Repository\CentreRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
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
                'choice_label' => 'nom',
                'label' => 'Centre de dépôt',
                'placeholder' => 'Sélectionnez un centre',
                'query_builder' => static fn (CentreRepository $repository) => $repository
                    ->createQueryBuilder('c')
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
            ->add('nbAnneesExperience', IntegerType::class, [
                'label' => "Années d'expérience dans le métier",
                'attr' => ['min' => 0, 'max' => 60],
            ])
            // La liste dépend du couple (centre, métier) : elle est donc vide au
            // premier affichage et peuplée en JavaScript après le choix du
            // métier, comme l'est déjà le champ « Métier visé » après le centre.
            // Le serveur revérifie le choix (contrainte CertificationOfferte).
            ->add('certification', EntityType::class, [
                'class' => Certification::class,
                'choice_label' => 'libelle',
                'label' => 'Diplôme de certification visé',
                'placeholder' => 'Sélectionnez d\'abord un métier',
                'choices' => $options['certifications_disponibles'],
            ])
            ->add('situationPro', ChoiceType::class, [
                'label' => 'Situation professionnelle',
                'placeholder' => 'Sélectionnez',
                'choices' => [
                    'Artisan à son compte' => 'ARTISAN A SON COMPTE',
                    'Salarié d\'une entreprise' => 'SALARIE',
                    'Apprenti' => 'APPRENTI',
                    'Aide familial' => 'AIDE FAMILIAL',
                    'Sans emploi' => 'SANS EMPLOI',
                ],
            ])
            ->add('diplome', ChoiceType::class, [
                'label' => 'Diplôme académique obtenu(e)',
                'placeholder' => 'Aucun',
                'required' => false,
                'choices' => [
                    'CEPE' => 'CEPE',
                    'BEPC' => 'BEPC',
                    'BAC' => 'BAC',
                    'Supérieur au BAC' => 'SUPERIEUR',
                    'Autre' => 'AUTRE',
                ],
            ])
            ->add('titrepro', TextType::class, [
                'label' => 'Titre professionnel',
                'required' => false,
                'help' => 'Intitulé sous lequel vous exercez, par exemple « maître boulanger ».',
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
            ->add('contactemployeur', TextType::class, [
                'label' => 'Contact de l\'employeur',
                'required' => false,
            ])
            ->add('langue', ChoiceType::class, [
                'label' => 'Langue d\'évaluation',
                'placeholder' => 'Sélectionnez',
                'required' => false,
                'choices' => [
                    'Français' => 'FRANCAIS',
                    'Langue locale' => 'LANGUE LOCALE',
                    'Autre' => 'AUTRE',
                ],
            ])
            ->add('preciserlangue', TextType::class, [
                'label' => 'Préciser la langue',
                'required' => false,
            ]);

        foreach ($this->champsDocuments() as $champ => $libelle) {
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

        // Un numéro de téléphone saisi avec des espaces ou des tirets
        // ("07 01 02 03 04") est un contact valide : on le nettoie avant la
        // validation plutôt que de rejeter une saisie humaine raisonnable.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $donnees = $event->getData();

            if (is_array($donnees) && is_string($donnees['contactemployeur'] ?? null)) {
                $donnees['contactemployeur'] = preg_replace('/[^\d]/', '', $donnees['contactemployeur']);
                $event->setData($donnees);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function champsDocuments(): array
    {
        return CandidatureDepotDto::documentsObligatoires();
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CandidatureDepotDto::class,
            'csrf_token_id' => 'candidature_depot',
            'centre_impose' => null,
            'metiers_disponibles' => [],
            'certifications_disponibles' => [],
            'post_max_size_message' => 'Le poids total des pièces jointes dépasse la limite autorisée par le serveur ({{ max }}). Réduisez la taille de vos fichiers ou envoyez-les en plusieurs fois.',
        ]);

        $resolver->setAllowedTypes('centre_impose', ['null', Centre::class]);
        $resolver->setAllowedTypes('metiers_disponibles', 'array');
        $resolver->setAllowedTypes('certifications_disponibles', 'array');    }
}
