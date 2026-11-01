<?php

namespace App\Form;

use App\Entity\Inquiry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class InquiryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, ['empty_data' => '', 'constraints' => [new NotBlank()]])
            ->add('email', EmailType::class, ['empty_data' => '', 'constraints' => [new NotBlank(), new Email()]])
            ->add('subject', null, ['empty_data' => '', 'constraints' => [new NotBlank()]])
            ->add('message', null, ['empty_data' => '', 'constraints' => [new NotBlank()]])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Inquiry::class,
        ]);
    }
}
