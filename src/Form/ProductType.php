<?php

namespace App\Form;

use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name')
            ->add('sku')
            ->add('slug')
            ->add('description')
            ->add('price')
            ->add('costPrice')
            ->add('salePrice')
            ->add('unit')
            ->add('lowStockThreshold')
            ->add('imageFile', FileType::class, [
                'label' => 'Image (JPEG, PNG or WebP, up to 2 MB)',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File(
                        maxSize: '2M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Please upload a JPEG, PNG or WebP image.',
                    ),
                ],
            ])
            ->add('status', ChoiceType::class, [
                'choices' => [
                    'Active' => Product::STATUS_ACTIVE,
                    'Draft' => Product::STATUS_DRAFT,
                    'Archived' => Product::STATUS_ARCHIVED,
                ],
            ])
            ->add('isFeatured')
            ->add('specifications', TextareaType::class, [
                'required' => false,
                'help' => 'Optional JSON object, for example {"length": "2m"}',
                'invalid_message' => 'Please enter valid JSON.',
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'choice_label' => 'name',
            ])
            ->add('brand', EntityType::class, [
                'class' => Brand::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'No brand',
            ])
        ;

        // Stock changes on existing products must go through a stock adjustment so they are audited.
        if (null === ($options['data'] ?? null)?->getId()) {
            $builder->add('stock', null, ['label' => 'Opening stock']);
        }

        $builder->get('specifications')->addModelTransformer(new CallbackTransformer(
            static fn (?array $value): string => null === $value ? '' : json_encode($value, JSON_PRETTY_PRINT),
            static function (?string $value): ?array {
                if (null === $value || '' === trim($value)) {
                    return null;
                }
                $decoded = json_decode($value, true);
                if (!is_array($decoded)) {
                    throw new TransformationFailedException('Specifications must be a JSON object or array.');
                }

                return $decoded;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
        ]);
    }
}
