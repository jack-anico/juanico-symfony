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

        // Only the current status and valid next steps can be submitted.
        // Cancelling has its own action because it requires a reason.
        $statuses = array_diff(
            [$order->getStatus(), ...(Order::TRANSITIONS[$order->getStatus()] ?? [])],
            $order->getStatus() === Order::STATUS_CANCELLED ? [] : [Order::STATUS_CANCELLED],
        );

        $builder
            ->add('status', ChoiceType::class, [
                'choices' => array_combine(array_map('ucfirst', $statuses), $statuses),
            ])
            ->add('paymentStatus', ChoiceType::class, [
                'choices' => [
                    'Unpaid' => Order::PAYMENT_UNPAID,
                    'Paid' => Order::PAYMENT_PAID,
                    'Refunded' => Order::PAYMENT_REFUNDED,
                ],
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
