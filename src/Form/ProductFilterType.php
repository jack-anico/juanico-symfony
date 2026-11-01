<?php

namespace App\Form;

use App\Entity\Brand;
use App\Entity\Category;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $activeOnly = static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('e')
            ->andWhere('e.isActive = true')
            ->orderBy('e.name', 'ASC');

        $builder
            ->add('q', SearchType::class, ['required' => false, 'label' => 'Search'])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'choice_label' => 'name',
                'query_builder' => $activeOnly,
                'required' => false,
                'placeholder' => 'All categories',
            ])
            ->add('brand', EntityType::class, [
                'class' => Brand::class,
                'choice_label' => 'name',
                'query_builder' => $activeOnly,
                'required' => false,
                'placeholder' => 'All brands',
            ])
            ->add('minPrice', NumberType::class, ['required' => false, 'input' => 'string', 'scale' => 2])
            ->add('maxPrice', NumberType::class, ['required' => false, 'input' => 'string', 'scale' => 2])
            ->add('sort', ChoiceType::class, [
                'required' => false,
                'placeholder' => 'Newest',
                'choices' => [
                    'Price: low to high' => 'price_asc',
                    'Price: high to low' => 'price_desc',
                    'Name' => 'name',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method' => 'GET',
            'csrf_protection' => false,
        ]);
    }
}
