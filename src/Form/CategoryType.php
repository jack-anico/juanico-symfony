<?php

namespace App\Form;

use App\Entity\Category;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentId = ($options['data'] ?? null)?->getId();

        $builder
            ->add('name')
            ->add('slug')
            ->add('description')
            ->add('image')
            ->add('displayOrder')
            ->add('isActive')
            ->add('parent', EntityType::class, [
                'class' => Category::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'None (top level)',
                // A category cannot be its own parent.
                'query_builder' => static function (EntityRepository $repository) use ($currentId): QueryBuilder {
                    $qb = $repository->createQueryBuilder('c')->orderBy('c.name', 'ASC');
                    if (null !== $currentId) {
                        $qb->andWhere('c.id <> :current')->setParameter('current', $currentId);
                    }

                    return $qb;
                },
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Category::class,
        ]);
    }
}
