<?php

namespace App\Twig;

use App\Entity\PlatformSetting;
use App\Entity\User;
use App\Repository\CartItemRepository;
use App\Repository\CategoryRepository;
use App\Repository\PlatformSettingRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final class AppExtension
{
    public function __construct(
        private Security $security,
        private CartItemRepository $cartItems,
        private CategoryRepository $categories,
        private PlatformSettingRepository $settings,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /** Display only: money calculations stay in bcmath. */
    #[AsTwigFilter('peso')]
    public function peso(string|int|float|null $value): string
    {
        return '₱'.number_format((float) $value, 2);
    }

    #[AsTwigFunction('cart_count')]
    public function cartCount(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->cartItems->countByOwner($user) : 0;
    }

    /** The first active top-level category with one of these slugs, or the whole catalog if none exists. */
    #[AsTwigFunction('catalog_url')]
    public function catalogUrl(string ...$slugs): string
    {
        foreach ($this->categories->findActiveRoots() as $category) {
            if (in_array($category->getSlug(), $slugs, true)) {
                return $this->urls->generate('app_catalog', ['category' => $category->getId()]);
            }
        }

        return $this->urls->generate('app_catalog');
    }

    /** Layout text must not take the whole site down, so a missing setting returns null here. */
    #[AsTwigFunction('shop_setting')]
    public function shopSetting(string $key): ?string
    {
        return $this->settings->findOneBy(['settingKey' => $key]) instanceof PlatformSetting
            ? $this->settings->getValue($key)
            : null;
    }
}
