<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Dépôt d'une preuve au livret (candidat ou accompagnateur). Le type réel du
 * fichier est contrôlé sur son contenu, pas sur son extension.
 */
class PreuveLivretType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, [
                'label' => 'Titre de la preuve',
                'attr' => ['maxlength' => 150, 'placeholder' => 'Ex. : Photo d\'un meuble réalisé en 2023'],
                'constraints' => [
                    new NotBlank(message: 'Donnez un titre à la preuve.'),
                    new Length(max: 150),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 1000],
                'constraints' => [new Length(max: 1000)],
            ])
            ->add('fichier', FileType::class, [
                'label' => 'Fichier',
                'constraints' => [
                    new NotNull(message: 'Choisissez un fichier.'),
                    new File(
                        maxSize: '5M',
                        mimeTypes: ['application/pdf', 'image/jpeg', 'image/png'],
                        mimeTypesMessage: 'Formats acceptés : PDF, JPG ou PNG.',
                        maxSizeMessage: 'Le fichier ne doit pas dépasser {{ limit }} {{ suffix }}.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'preuve_livret']);
    }
}
