<?php

namespace App\Form;

use App\Entity\Order;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OrderStatusType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Order $order */
        $order = $options['data'];
        $current = (string) $order->getStatus();

        // Unmapped, so only OrderTransitionService can change the status.
        // Cancelling has its own action because it requires a reason.
        $statuses = array_values(array_unique(array_filter(
            [$current, ...(Order::TRANSITIONS[$current] ?? [])],
            static fn (string $status): bool => Order::STATUS_CANCELLED !== $status || Order::STATUS_CANCELLED === $current,
        )));

        $builder
            ->add('status', ChoiceType::class, [
                'mapped' => false,
                'data' => $current,
                'choices' => array_combine(array_map('ucfirst', $statuses), $statuses),
            ])
            ->add('trackingNumber', null, ['required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Order::class,
        ]);
    }
}
