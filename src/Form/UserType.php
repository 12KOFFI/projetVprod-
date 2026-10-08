<?php

namespace App\Form;

use App\Entity\Centre;
use App\Entity\Metier;
use App\Entity\User;
use App\Form\Type\TelephoneType;
use App\Referentiel\Nationalites;
use App\Repository\CentreRepository;
use App\Repository\MetierRepository;
use App\Security\Role;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\EqualTo;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isRegister = $options['is_register'];

        $builder
            ->add('nom', TextType::class, ['required' => !$isRegister, 'constraints' => [new Length(max: 150)]])
            ->add('prenoms', TextType::class, [
                'label' => 'Prénoms',
                'required' => !$isRegister,
                'constraints' => [new Length(max: 150)],
            ])
            ->add('sexe', ChoiceType::class, [
                'choices' => ['Féminin' => 'F', 'Masculin' => 'M'],
                'placeholder' => 'Sélectionnez',
                'required' => !$isRegister,
            ])
            ->add('situationmat', ChoiceType::class, [
                'label' => 'Situation matrimoniale',
                'choices' => [
                    'CELIBATAIRE' => 'CELIBATAIRE',
                    'UNION LIBRE' => 'UNION LIBRE',
                    'MARIE(E)' => 'MARIE(E)',
                    'VEUVE' => 'VEUVE',
                ],
                'placeholder' => 'Sélectionnez',
                'required' => false,
            ])
            ->add('datenaissance', DateType::class, $isRegister
                ? [
                    // Inscription : un simple champ texte « jj/mm/aaaa » (les barres
                    // sont posées pendant la frappe), le calendrier restant en option.
                    'label' => 'Date de naissance',
                    'widget' => 'single_text',
                    'input' => 'datetime',
                    'html5' => false,
                    'format' => 'dd/MM/yyyy',
                    'required' => false,
                    'invalid_message' => 'Saisissez une date valide au format jj/mm/aaaa, par exemple 15/03/1985.',
                    'attr' => ['placeholder' => 'jj/mm/aaaa', 'inputmode' => 'numeric', 'maxlength' => 10, 'autocomplete' => 'bday'],
                    'constraints' => [new LessThanOrEqual(value: 'today', message: 'La date de naissance ne peut pas être dans le futur.')],
                ]
                : [
                    'label' => 'Date de naissance',
                    'widget' => 'single_text',
                    'input' => 'datetime',
                    'required' => true,
                ])
            ->add('lieunaissance', TextType::class, [
                'label' => 'Lieu de naissance',
                'required' => !$isRegister,
                'constraints' => [new Length(max: 150)],
            ])
            ->add('nationalite', ChoiceType::class, $isRegister
                ? [
                    // Inscription : toutes les nationalités sont proposées ; la
                    // condition de candidature est appliquée à la soumission.
                    'label' => 'Nationalité',
                    'choices' => Nationalites::choix(),
                    'placeholder' => 'Sélectionnez votre nationalité',
                    'required' => true,
                    'constraints' => [
                        new NotBlank(message: 'Sélectionnez votre nationalité.'),
                        new EqualTo(
                            value: User::NATIONALITE_IVOIRIENNE,
                            message: 'La VAE est réservée aux personnes de nationalité ivoirienne : votre préinscription ne peut pas être enregistrée.',
                        ),
                    ],
                ]
                : [
                    'label' => 'Nationalité',
                    'choices' => [User::NATIONALITE_IVOIRIENNE => User::NATIONALITE_IVOIRIENNE],
                    'placeholder' => false,
                    'required' => false,
                    'disabled' => true,
                ])
            ->add('residence', TextType::class, [
                'label' => 'Ville de résidence',
                'required' => !$isRegister,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('boitePostale', TextType::class, [
                'label' => 'Boîte postale',
                'required' => false,
                'constraints' => [new Length(max: 50)],
            ])
            ->add('contact', TelephoneType::class, [
                'label' => $isRegister ? 'Contact 1' : 'Téléphone portable',
                'required' => !$isRegister,
            ])
            ->add('email', EmailType::class, [
                'required' => !$isRegister,
                'constraints' => [new Email(), new Length(max: 150)],
            ])
        ;

        // Hors inscription, la nationalité est imposée et le champ désactivé : Symfony
        // ne la lit pas de la requête. On la pose ici pour qu'un compte sans valeur
        // (création, ancien compte) ne reste pas vide. À l'inscription, elle est
        // présélectionnée sur « Ivoirienne » et vérifiée à la soumission.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $evenement): void {
            $utilisateur = $evenement->getData();

            if ($utilisateur instanceof User && $utilisateur->getNationalite() === null) {
                $utilisateur->setNationalite(User::NATIONALITE_IVOIRIENNE);
            }
        });

        // Limité à l'inscription : les autres écrans n'affichent pas ce champ, et
        // un champ absent de la requête remettrait contact2 à null à l'enregistrement.
        if ($isRegister) {
            // Indicatif au choix ; le numéro est enregistré au format international.
            $builder->add('contact2', TelephoneType::class, [
                'label' => 'Contact 2',
                'required' => false,
            ]);
        }

        // Création d'un compte du personnel par l'administrateur : le mot de passe
        // initial est généré aléatoirement par GestionCompte et communiqué hors
        // bande, il n'est donc pas saisi ici (règle métier R2.9). En revanche le
        // rôle et les rattachements deviennent obligatoires.
        if ($options['is_admin_creation']) {
            $this->ajouterChampsPersonnel($builder);

            return;
        }

        // Inscription publique comme assistée : un compte n'est jamais créé sans
        // mot de passe (8 caractères minimum).
        $builder->add('password', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'required' => true,
            'options' => ['attr' => ['minlength' => 8, 'autocomplete' => 'new-password']],
            'first_options' => ['label' => 'Mot de passe'],
            'second_options' => ['label' => 'Confirmation du mot de passe'],
            'constraints' => [
                new NotBlank(message: 'Le mot de passe est obligatoire.'),
                new Length(min: 8, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.'),
            ],
            'invalid_message' => 'Les mots de passe ne correspondent pas.',
        ]);

        // Attestation sur l'honneur du candidat qui s'inscrit lui-même : la case
        // doit être cochée, à l'écran comme au serveur.
        if ($options['attestation']) {
            $builder->add('attestation', CheckboxType::class, [
                'mapped' => false,
                'required' => true,
                'label' => "J'accepte que les informations fournies soient utilisées dans le cadre de ma candidature et j'atteste sur l'honneur leur exactitude.",
                'constraints' => [
                    new IsTrue(message: "Cochez la case pour attester sur l'honneur l'exactitude de vos informations."),
                ],
            ]);
        }
    }

    /**
     * Rôle et rattachements d'un compte interne (F2.7).
     *
     * Le rôle n'est pas mappé : User::$roles est un tableau JSON, et c'est
     * GestionCompte::creerPersonnel() qui le normalise à un rôle unique en
     * purgeant les rattachements devenus incohérents. ROLE_JURY n'est
     * volontairement pas proposé (décision P4 de final.txt).
     */
    private function ajouterChampsPersonnel(FormBuilderInterface $builder): void
    {
        $libelles = Role::libelles();
        unset($libelles[Role::CANDIDAT]);

        $builder
            ->add('role', ChoiceType::class, [
                'mapped' => false,
                'label' => 'Rôle',
                'choices' => array_flip($libelles),
                'placeholder' => 'Sélectionnez un rôle',
                'constraints' => [
                    new NotBlank(message: 'Le rôle est obligatoire.'),
                    new Choice(choices: array_keys($libelles)),
                ],
                'help' => "Le rattachement à un centre est obligatoire pour tout rôle autre qu'administrateur.",
            ])
            ->add('centre', EntityType::class, [
                'class' => Centre::class,
                'choice_label' => 'nom',
                'label' => 'Centre de rattachement',
                'placeholder' => 'Sélectionnez un centre',
                'required' => false,
                'query_builder' => static fn (CentreRepository $repository) => $repository
                    ->createQueryBuilder('c')
                    ->orderBy('c.nom', 'ASC'),
            ])
            ->add('metier', EntityType::class, [
                'class' => Metier::class,
                'choice_label' => 'libelle',
                'label' => 'Métier suivi',
                'placeholder' => 'Sélectionnez un métier',
                'required' => false,
                'group_by' => static fn (Metier $metier): string => (string) $metier->getFiliere()?->getLibelle(),
                'query_builder' => static fn (MetierRepository $repository) => $repository
                    ->createQueryBuilder('m')
                    ->join('m.filiere', 'f')
                    ->andWhere('m.statut = :actif')
                    ->setParameter('actif', 'actif')
                    ->orderBy('f.libelle', 'ASC')
                    ->addOrderBy('m.libelle', 'ASC'),
                'help' => 'Obligatoire pour un accompagnateur uniquement.',
            ]);

        // Le rôle est reporté sur l'entité AVANT la validation : la contrainte de
        // classe RattachementUtilisateur (V1.2 et V1.3) inspecte User::getRoles(),
        // qui vaudrait encore [ROLE_CANDIDAT] à cet instant. Sans cela un
        // accompagnateur pourrait être enregistré sans métier de rattachement.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $evenement): void {
            $formulaire = $evenement->getForm();
            $utilisateur = $evenement->getData();

            if (!$utilisateur instanceof User || !$formulaire->has('role')) {
                return;
            }

            $role = $formulaire->get('role')->getData();

            if (is_string($role) && $role !== '') {
                $utilisateur->setRoles([$role]);
            }
        }, 100);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'csrf_token_id' => 'user_registration',
            'is_register' => false,
            'is_edit' => false,
            'is_admin_creation' => false,
            'attestation' => false,
        ]);
        $resolver->setAllowedTypes('attestation', 'bool');
    }
}
