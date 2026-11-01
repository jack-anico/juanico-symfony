<?php

namespace App\Form;

use App\Entity\Address;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class CheckoutType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $user = $options['user'];

        $builder
            ->add('idempotencyKey', HiddenType::class, [
                'constraints' => [
                    new NotBlank(),
                    new Regex('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'),
                ],
            ])
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
                'choices' => [
                    'Standard' => Order::SHIPPING_STANDARD,
                    'Express' => Order::SHIPPING_EXPRESS,
                    'Store pickup (free)' => Order::SHIPPING_PICKUP,
                ],
            ])
            ->add('paymentMethod', ChoiceType::class, [
                'choices' => [
                    'Cash on delivery' => Payment::METHOD_COD,
                    'GCash (simulated)' => Payment::METHOD_GCASH,
                    'Card (simulated)' => Payment::METHOD_CARD,
                    'Bank transfer' => Payment::METHOD_BANK,
                ],
            ])
            ->add('paymentProof', FileType::class, [
                'label' => 'Payment proof (required for bank transfer)',
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
        $resolver->setDefaults([
            // Anything else a client sends, such as a price, is ignored rather than trusted.
            'allow_extra_fields' => true,
        ]);
        $resolver->setRequired('user');
        $resolver->setAllowedTypes('user', User::class);
    }
}
