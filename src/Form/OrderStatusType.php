<?php

namespace App\Form;

use App\Entity\Address;
use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OrderStatusType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('orderNumber')
            ->add('status')
            ->add('paymentMethod')
            ->add('paymentStatus')
            ->add('shippingMethod')
            ->add('paymentProof')
            ->add('cancelReason')
            ->add('subtotal')
            ->add('shippingFee')
            ->add('discount')
            ->add('total')
            ->add('notes')
            ->add('trackingNumber')
            ->add('createdAt', null, [
                'widget' => 'single_text'
            ])
            ->add('paidAt', null, [
                'widget' => 'single_text'
            ])
            ->add('deliveredAt', null, [
                'widget' => 'single_text'
            ])
            ->add('customer', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'id',
            ])
            ->add('shippingAddress', EntityType::class, [
                'class' => Address::class,
                'choice_label' => 'id',
            ])
            ->add('discountCode', EntityType::class, [
                'class' => DiscountCode::class,
                'choice_label' => 'id',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Order::class,
        ]);
    }
}
