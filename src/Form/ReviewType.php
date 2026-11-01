<?php

namespace App\Form;

use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ReviewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rating')
            ->add('comment')
            ->add('isApproved')
            ->add('createdAt', null, [
                'widget' => 'single_text'
            ])
            ->add('author', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'id',
            ])
            ->add('product', EntityType::class, [
                'class' => Product::class,
                'choice_label' => 'id',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Review::class,
        ]);
    }
}
