<?php

namespace App\Form;

use App\Entity\Address;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class CheckoutType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $user = $options['user'];

        $builder
            ->add('address', EntityType::class, [
                'class' => Address::class,
                // Only the customer's own addresses are valid choices.
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('a')
                    ->andWhere('a.owner = :owner')
                    ->setParameter('owner', $user)
                    ->orderBy('a.isDefault', 'DESC'),
                'choice_label' => static fn (Address $address): string => sprintf(
                    '%s: %s, %s, %s',
                    $address->getLabel(),
                    $address->getLine1(),
                    $address->getCity(),
                    $address->getProvince(),
                ),
            ])
            ->add('shippingMethod', ChoiceType::class, [
                'choices' => array_combine(
                    array_map(
                        static fn (string $method, string $fee): string => sprintf('%s (PHP %s)', ucfirst($method), $fee),
                        array_keys(Order::SHIPPING_FEES),
                        Order::SHIPPING_FEES,
                    ),
                    array_keys(Order::SHIPPING_FEES),
                ),
            ])
            ->add('paymentMethod', ChoiceType::class, [
                'choices' => [
                    'Cash on delivery' => Order::PAYMENT_COD,
                    'GCash' => Order::PAYMENT_GCASH,
                    'Bank transfer' => Order::PAYMENT_BANK,
                ],
            ])
            ->add('paymentProof', FileType::class, [
                'label' => 'Payment proof (required for GCash and bank transfer)',
                'required' => false,
                'constraints' => [
                    new File(
                        maxSize: '2M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Please upload a JPEG, PNG or WebP image.',
                    ),
                ],
            ])
            ->add('discountCode', TextType::class, ['required' => false])
            ->add('notes', TextareaType::class, ['required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('user');
        $resolver->setAllowedTypes('user', User::class);
    }
}
