<?php

declare(strict_types=1);

namespace Nedac\SyliusOrderNowPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\OrderBundle\Controller\AddToCartCommandInterface;
use Sylius\Bundle\ResourceBundle\Controller\EventDispatcherInterface;
use Sylius\Bundle\ResourceBundle\Controller\FlashHelperInterface;
use Sylius\Bundle\ResourceBundle\Controller\NewResourceFactoryInterface;
use Sylius\Bundle\ResourceBundle\Controller\RedirectHandlerInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfigurationFactoryInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Order\CartActions;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Resource\Metadata\MetadataInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;

/**
 * @property MetadataInterface $metadata
 * @property RequestConfigurationFactoryInterface $requestConfigurationFactory
 * @property RedirectHandlerInterface $redirectHandler
 * @property FlashHelperInterface $flashHelper
 * @property NewResourceFactoryInterface $newResourceFactory
 * @property EventDispatcherInterface $eventDispatcher
 * @method FormFactoryInterface getFormFactory()
 * @method void addFlash(string $type, string $message)
 * @method object get(string $id)
 * @method OrderInterface getCurrentCart()
 * @method void isGrantedOr403(RequestConfiguration $configuration, string $permission)
 * @method OrderItemQuantityModifierInterface getQuantityModifier()
 * @method AddToCartCommandInterface createAddToCartCommand(OrderInterface $cart, OrderItemInterface $cartItem)
 * @method OrderModifierInterface getOrderModifier()
 * @method EntityManagerInterface getCartManager()
 */
trait OrderItemControllerTrait
{
    public function addAction(Request $request): Response
    {
        $cart = $this->getCurrentCart();
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);

        $this->isGrantedOr403($configuration, CartActions::ADD);
        /** @var OrderItemInterface $orderItem */
        $orderItem = $this->newResourceFactory->create($configuration, $this->factory);

        $this->getQuantityModifier()->modify($orderItem, 1);

        $formType = $configuration->getFormType();
        Assert::notNull($formType);

        $form = $this->getFormFactory()->create(
            $formType,
            $this->createAddToCartCommand($cart, $orderItem),
            $configuration->getFormOptions()
        );

        if ($request->isMethod('POST') && $form->handleRequest($request)->isValid()) {
            /** @var AddToCartCommandInterface $addToCartCommand */
            $addToCartCommand = $form->getData();

            $event = $this->eventDispatcher->dispatchPreEvent(CartActions::ADD, $configuration, $orderItem);

            if ($event->isStopped()) {
                $this->flashHelper->addFlashFromEvent($configuration, $event);

                return $this->redirectHandler->redirectToReferer($configuration);
            }

            $this->getOrderModifier()->addToOrder($addToCartCommand->getCart(), $addToCartCommand->getCartItem());

            $cartManager = $this->getCartManager();
            $cartManager->persist($cart);
            $cartManager->flush();

            $resourceControllerEvent = $this->eventDispatcher->dispatchPostEvent(
                CartActions::ADD,
                $configuration,
                $orderItem
            );
            if (
                $resourceControllerEvent->hasResponse() &&
                null !== $response = $resourceControllerEvent->getResponse()
            ) {
                return $response;
            }

            $this->flashHelper->addSuccessFlash($configuration, CartActions::ADD, $orderItem);
        } else {
            $errors = $form->getErrors();
            foreach ($errors as $error) {
                $this->addFlash('error', $error->getMessage());
            }
        }

        $parameters = $configuration->getParameters();
        $redirect = $parameters->get('redirect');
        if (null === $redirect) {
            $redirect = [];
        }
        $product = $orderItem->getProduct();
        if (null !== $product) {
            $redirect['referer'] = $configuration->getRedirectReferer() . '#' . $product->getId();
            $parameters->set('redirect', $redirect);
        }

        return $this->redirectHandler->redirectToReferer($configuration);
    }
}
