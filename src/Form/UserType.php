<?php

namespace App\Form;

use App\Entity\Centre;
use App\Entity\Metier;
use App\Entity\User;
use App\Repository\CentreRepository;
use App\Repository\MetierRepository;
use App\Security\Role;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
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
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

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
            ->add('nomJeuneFille', TextType::class, [
                'label' => 'Nom de jeune fille',
                'required' => false,
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
            ->add('datenaissance', DateType::class, [
                'label' => 'Date de naissance',
                'widget' => 'single_text',
                'input' => 'datetime',
                'required' => !$isRegister,
            ])
            ->add('lieunaissance', TextType::class, [
                'label' => 'Lieu de naissance',
                'required' => !$isRegister,
                'constraints' => [new Length(max: 150)],
            ])
            ->add('nationalite', ChoiceType::class, [
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
            ->add('contact', TextType::class, [
                'label' => 'Téléphone portable',
                'required' => !$isRegister,
                'constraints' => [new Regex('/^\d{10}$/', 'Le contact doit contenir exactement 10 chiffres.')],
            ])
            ->add('email', EmailType::class, [
                'required' => !$isRegister,
                'constraints' => [new Email(), new Length(max: 150)],
            ])
        ;

        // La nationalité est imposée (condition de candidature) : le champ est
        // désactivé, donc Symfony ne la lit pas de la requête. On la pose ici pour
        // qu'un compte sans valeur (création, ancien compte) ne reste pas vide.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $evenement): void {
            $utilisateur = $evenement->getData();

            if ($utilisateur instanceof User && $utilisateur->getNationalite() === null) {
                $utilisateur->setNationalite(User::NATIONALITE_IVOIRIENNE);
            }
        });

        // Limité à l'inscription : les autres écrans n'affichent pas ce champ, et
        // un champ absent de la requête remettrait contact2 à null à l'enregistrement.
        if ($isRegister) {
            $builder->add('contact2', TextType::class, [
                'label' => 'Téléphone fixe',
                'required' => false,
                'constraints' => [new Regex('/^\d{10}$/', 'Le téléphone fixe doit contenir exactement 10 chiffres.')],
            ]);

            // Le gabarit affiche les numéros par paires (07 08 09 10 11) : on retire
            // les espaces avant validation pour que la règle des 10 chiffres s'applique.
            $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $evenement): void {
                $donnees = $evenement->getData();

                foreach (['contact', 'contact2'] as $champ) {
                    if (is_array($donnees) && isset($donnees[$champ]) && is_string($donnees[$champ])) {
                        $donnees[$champ] = preg_replace('/[\s.\-]+/', '', $donnees[$champ]);
                    }
                }

                $evenement->setData($donnees);
            });
        }

        // Création d'un compte du personnel par l'administrateur : le mot de passe
        // initial est généré aléatoirement par GestionCompte et communiqué hors
        // bande, il n'est donc pas saisi ici (règle métier R2.9). En revanche le
        // rôle et les rattachements deviennent obligatoires.
        if ($options['is_admin_creation']) {
            $this->ajouterChampsPersonnel($builder);

            return;
        }

        // En inscription assistée, l'agent d'accueil choisit le mot de passe avec
        // le candidat : il est alors obligatoire, même en mode inscription.
        $motDePasseObligatoire = !$isRegister || $options['mot_de_passe_obligatoire'];

        $builder->add('password', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'required' => $motDePasseObligatoire,
            'options' => $motDePasseObligatoire ? ['attr' => ['minlength' => 8, 'autocomplete' => 'new-password']] : [],
            'first_options' => ['label' => 'Mot de passe'],
            'second_options' => ['label' => 'Confirmation du mot de passe'],
            'constraints' => $motDePasseObligatoire
                ? [
                    new NotBlank(message: 'Le mot de passe est obligatoire.'),
                    new Length(min: 8, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.'),
                ]
                : [],
            'invalid_message' => 'Les mots de passe ne correspondent pas.',
        ]);
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
            'mot_de_passe_obligatoire' => false,
        ]);
        $resolver->setAllowedTypes('mot_de_passe_obligatoire', 'bool');
    }
}
