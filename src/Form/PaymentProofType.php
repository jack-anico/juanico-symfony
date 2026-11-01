<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class PaymentProofType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('proof', FileType::class, [
                'label' => 'New proof of payment',
                'constraints' => [
                    new NotBlank(),
                    new File(
                        maxSize: '2M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Please upload a JPEG, PNG or WebP image.',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
