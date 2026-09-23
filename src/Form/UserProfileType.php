<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Modification par un utilisateur de ses propres informations personnelles
 * (tous rôles confondus, via ProfilController).
 *
 * Volontairement distinct de UserType : ni rôle ni rattachement centre/métier
 * n'y figurent — ils relèvent de l'administration du personnel.
 *
 * Le changement de mot de passe est facultatif et enregistré avec le reste du
 * profil : laissé vide, le mot de passe actuel est conservé.
 */
class UserProfileType extends AbstractType
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['constraints' => [new Length(max: 150)]])
            ->add('prenoms', TextType::class, ['constraints' => [new Length(max: 150)]])
            ->add('nomJeuneFille', TextType::class, [
                'label' => 'Nom de jeune fille',
                'required' => false,
                'help' => 'Uniquement si différent du nom actuel.',
                'constraints' => [new Length(max: 150)],
            ])
            ->add('sexe', ChoiceType::class, [
                'choices' => ['Féminin' => 'F', 'Masculin' => 'M'],
                'placeholder' => 'Sélectionnez',
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
            ])
            ->add('lieunaissance', TextType::class, [
                'label' => 'Lieu de naissance',
                'constraints' => [new Length(max: 150)],
            ])
            ->add('nationalite', ChoiceType::class, [
                'choices' => [User::NATIONALITE_IVOIRIENNE => User::NATIONALITE_IVOIRIENNE],
                'placeholder' => false,
                'required' => false,
                'disabled' => true,
            ])
            ->add('residence', TextType::class, [
                'label' => 'Ville de résidence',
                'constraints' => [new Length(max: 255)],
            ])
            ->add('boitePostale', TextType::class, [
                'label' => 'Boîte postale',
                'required' => false,
                'constraints' => [new Length(max: 50)],
            ])
            ->add('contact', TextType::class, [
                'label' => 'Téléphone portable',
                'constraints' => [new Regex('/^\d{10}$/', 'Le contact doit contenir exactement 10 chiffres.')],
            ])
            ->add('contact2', TextType::class, [
                'label' => 'Téléphone fixe',
                'required' => false,
                'constraints' => [new Regex('/^\d{10}$/', 'Le téléphone fixe doit contenir exactement 10 chiffres.')],
            ])
            ->add('email', EmailType::class, [
                'constraints' => [new Email(), new Length(max: 150)],
            ])
            ->add('motDePasseActuel', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'mapped' => false,
                'required' => false,
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('nouveauMotDePasse', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => false,
                'options' => ['attr' => ['minlength' => 8, 'autocomplete' => 'new-password']],
                'first_options' => ['label' => 'Nouveau mot de passe'],
                'second_options' => ['label' => 'Confirmation du nouveau mot de passe'],
                'invalid_message' => 'Les mots de passe ne correspondent pas.',
                'constraints' => [
                    new Length(min: 8, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.'),
                ],
            ]);

        // Nationalité imposée : champ désactivé, valeur posée ici si le compte n'en a pas.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $evenement): void {
            $utilisateur = $evenement->getData();

            if ($utilisateur instanceof User && $utilisateur->getNationalite() === null) {
                $utilisateur->setNationalite(User::NATIONALITE_IVOIRIENNE);
            }
        });

        // Le mot de passe actuel n'est exigé que si l'utilisateur en choisit un
        // nouveau : une session restée ouverte sur un poste partagé du centre ne
        // doit pas suffire à s'approprier le compte.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $evenement): void {
            $formulaire = $evenement->getForm();
            $utilisateur = $formulaire->getData();
            $actuel = (string) $formulaire->get('motDePasseActuel')->getData();
            $nouveau = (string) $formulaire->get('nouveauMotDePasse')->getData();

            // Confirmation différente : l'erreur « ne correspondent pas » suffit.
            if (!$formulaire->get('nouveauMotDePasse')->isSynchronized()) {
                return;
            }

            if (!$utilisateur instanceof User || ($actuel === '' && $nouveau === '')) {
                return;
            }

            if ($nouveau === '') {
                $formulaire->get('nouveauMotDePasse')->get('first')->addError(new FormError('Saisissez le nouveau mot de passe.'));

                return;
            }

            if ($actuel === '') {
                $formulaire->get('motDePasseActuel')->addError(new FormError('Saisissez votre mot de passe actuel pour le modifier.'));
            } elseif (!$this->passwordHasher->isPasswordValid($utilisateur, $actuel)) {
                $formulaire->get('motDePasseActuel')->addError(new FormError('Le mot de passe actuel est incorrect.'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'csrf_token_id' => 'user_profil',
        ]);
    }
}
