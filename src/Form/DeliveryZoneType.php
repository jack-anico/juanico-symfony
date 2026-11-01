<?php

namespace App\Form;

use App\Entity\DeliveryZone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

class DeliveryZoneType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, ['empty_data' => '', 'constraints' => [new NotBlank()]])
            ->add('provinces', TextareaType::class, [
                'help' => 'Provinces or cities covered, separated by commas',
                'constraints' => [new Count(min: 1, minMessage: 'List at least one province or city.')],
            ])
            ->add('shippingFee', null, ['constraints' => [new NotBlank(), new GreaterThanOrEqual(0)]])
            ->add('isActive')
        ;

        $builder->get('provinces')->addModelTransformer(new CallbackTransformer(
            static fn (?array $places): string => implode(', ', $places ?? []),
            static fn (?string $text): array => array_values(array_filter(array_map('trim', explode(',', (string) $text)), 'strlen')),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DeliveryZone::class,
        ]);
    }
}
