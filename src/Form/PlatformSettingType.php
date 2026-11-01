<?php

namespace App\Form;

use App\Entity\PlatformSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class PlatformSettingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('settingValue', null, [
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Regex(pattern: '/^\d+(\.\d{1,2})?$/', message: 'Enter a number with up to two decimals.'),
                ],
            ])
            ->add('description')
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlatformSetting::class,
        ]);
    }
}
