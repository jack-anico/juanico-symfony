<?php

namespace App\Form;

use App\Entity\DiscountCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DiscountCodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code')
            ->add('type', ChoiceType::class, [
                'choices' => ['Percent' => 'percent', 'Fixed amount' => 'fixed'],
            ])
            ->add('value')
            ->add('minSpend')
            ->add('usageLimit')
            ->add('expiresAt', null, [
                'widget' => 'single_text'
            ])
            ->add('isActive')
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DiscountCode::class,
        ]);
    }
}
