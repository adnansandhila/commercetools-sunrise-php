<?php
/**
 * @author @ct-jensschulze <jens.schulze@commercetools.de>
 */

namespace Commercetools\Sunrise\AppBundle\Controller;


use Commercetools\Core\Client;
use Commercetools\Core\Model\Cart\Cart;
use Commercetools\Core\Model\Common\Money;
use Commercetools\Sunrise\AppBundle\Model\View\ViewLink;
use Commercetools\Sunrise\AppBundle\Model\ViewData;
use Commercetools\Sunrise\AppBundle\Model\ViewDataCollection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CartController extends SunriseController
{
    const CSRF_TOKEN_NAME = 'csrfToken';

    public function index()
    {
        $session = $this->get('session');
        $viewData = $this->getViewData('Sunrise - Cart');
        $cartId = $session->get('cartId');
        $cart = $this->get('app.repository.cart')->getCart($cartId);
        $viewData->content = new ViewData();
        $viewData->content->cart = $this->getCart($cart);
        $viewData->meta->_links->continueShopping = new ViewLink($this->generateUrl('home'));
        $viewData->meta->_links->deleteLineItem = new ViewLink($this->generateUrl('lineItemDelete'));
        $viewData->meta->_links->changeLineItem = new ViewLink($this->generateUrl('lineItemChange'));
        $viewData->meta->_links->checkout = new ViewLink($this->generateUrl('checkout'));

        return $this->render('cart.hbs', $viewData->toArray());
    }

    public function add(Request $request)
    {
        $session = $this->get('session');
        // TODO: enable if product add form has a csrf token
//        if (!$this->validateCsrfToken(static::CSRF_TOKEN_FORM, $request->get(static::CSRF_TOKEN_NAME))) {
//            throw new \InvalidArgumentException('CSRF Token invalid');
//        }
        $productId = $request->get('productId');
        $variantId = (int)$request->get('variantId');
        $quantity = (int)$request->get('quantity');
        $sku = $request->get('productSku');
        $slug = $request->get('productSlug');
        $cartId = $session->get('cartId');
        $country = \Locale::getRegion($this->locale);
        $currency = $this->config->get('currencies.'. $country);
        $cart = $this->get('app.repository.cart')
            ->addLineItem($cartId, $productId, $variantId, $quantity, $currency, $country);
        $session->set('cartId', $cart->getId());
        $session->set('cartNumItems', $this->getItemCount($cart));
        $session->save();

        if (empty($sku)) {
            $redirectUrl = $this->generateUrl('pdp-master', ['slug' => $slug]);
        } else {
            $redirectUrl = $this->generateUrl('pdp', ['slug' => $slug, 'sku' => $sku]);
        }
        return new RedirectResponse($redirectUrl);
    }

    public function miniCart()
    {
        $response = new Response();
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->addCacheControlDirective('no-store');
        $response = $this->render('common/mini-cart.hbs', $this->getHeaderViewData('MiniCart')->toArray(), $response);

        return $response;
    }

    public function changeLineItem(Request $request)
    {
        if (!$this->validateCsrfToken(static::CSRF_TOKEN_FORM, $request->get(static::CSRF_TOKEN_NAME))) {
            throw new \InvalidArgumentException('CSRF Token invalid');
        }
        $session = $this->get('session');
        $lineItemId = $request->get('lineItemId');
        $lineItemCount = (int)$request->get('quantity');
        $cartId = $session->get('cartId');
        $cart = $this->get('app.repository.cart')
            ->changeLineItemQuantity($cartId, $lineItemId, $lineItemCount);

        $session->set('cartNumItems', $this->getItemCount($cart));
        $session->save();

        return new RedirectResponse($this->generateUrl('cart'));
    }

    public function deleteLineItem(Request $request)
    {
        $session = $this->get('session');
        $lineItemId = $request->get('lineItemId');
        $cartId = $session->get('cartId');
        $cart = $this->get('app.repository.cart')->deleteLineItem($cartId, $lineItemId);

        $session->set('cartNumItems', $this->getItemCount($cart));
        $session->save();

        return new RedirectResponse($this->generateUrl('cart'));
    }

    public function checkout(Request $request)
    {
        $session = $this->get('session');
        $userId = $session->get('userId');
        if (is_null($userId)) {
            return $this->checkoutSignin($request);
        }

        return $this->checkoutShipping($request);
    }

    public function checkoutSignin(Request $request)
    {
        $viewData = $this->getViewData('Sunrise - Checkout - Signin');
        return $this->render('checkout-signin.hbs', $viewData->toArray());
    }

    public function checkoutShipping(Request $request)
    {
        $viewData = $this->getViewData('Sunrise - Checkout - Shipping');
        return $this->render('checkout-shipping.hbs', $viewData->toArray());
    }

    public function checkoutPayment(Request $request)
    {
        $viewData = $this->getViewData('Sunrise - Checkout - Payment');
        return $this->render('checkout-payment.hbs', $viewData->toArray());
    }

    public function checkoutConfirmation(Request $request)
    {
        $viewData = $this->getViewData('Sunrise - Checkout - Confirmation');
        return $this->render('checkout-confirmation.hbs', $viewData->toArray());
    }

    protected function getItemCount(Cart $cart)
    {
        $count = 0;
        if ($cart->getLineItems()) {
            foreach ($cart->getLineItems() as $lineItem) {
                $count+= $lineItem->getQuantity();
            }
        }
        return $count;
    }

    protected function getCart(Cart $cart)
    {
        $cartModel = new ViewData();
        $cartModel->totalItems = $this->getItemCount($cart);
        if ($cart->getTaxedPrice()) {
            $salexTax = Money::ofCurrencyAndAmount(
                $cart->getTaxedPrice()->getTotalGross()->getCurrencyCode(),
                $cart->getTaxedPrice()->getTotalGross()->getCentAmount() -
                    $cart->getTaxedPrice()->getTotalNet()->getCentAmount(),
                $cart->getContext()
            );
            $cartModel->salesTax = $salexTax;
            $cartModel->subtotalPrice = $cart->getTaxedPrice()->getTotalNet();
            $cartModel->totalPrice = $cart->getTotalPrice();
        }
        if ($cart->getShippingInfo()) {
            $shippingInfo = $cart->getShippingInfo();
            $cartModel->shippingMethod = new ViewData();
            $cartModel->shippingMethod->value = $shippingInfo->getShippingMethodName();
            $cartModel->shippingMethod->label = $shippingInfo->getShippingMethodName();
            $cartModel->shippingMethod->price = (string)$shippingInfo->getPrice();
        }

        $cartModel->lineItems = $this->getCartLineItems($cart);

        return $cartModel;
    }

    protected function getCartLineItems(Cart $cart)
    {
        $cartItems = new ViewData();
        $cartItems->list = new ViewDataCollection();

        $lineItems = $cart->getLineItems();

        if (!is_null($lineItems)) {
            foreach ($lineItems as $lineItem) {
                $variant = $lineItem->getVariant();
                $cartLineItem = new ViewData();
                $cartLineItem->productId = $lineItem->getProductId();
                $cartLineItem->variantId = $variant->getId();
                $cartLineItem->lineItemId = $lineItem->getId();
                $cartLineItem->quantity = $lineItem->getQuantity();
                $lineItemVariant = new ViewData();
                $lineItemVariant->url = (string)$this->generateUrl(
                    'pdp-master',
                    ['slug' => (string)$lineItem->getProductSlug()]
                );
                $lineItemVariant->name = (string)$lineItem->getName();
                $lineItemVariant->image = (string)$variant->getImages()->current()->getUrl();
                $cartLineItem->variant = $lineItemVariant;
                $cartLineItem->sku = $variant->getSku();
                $price = $lineItem->getPrice();
                if (!is_null($price->getDiscounted())) {
                    $cartLineItem->price = (string)$price->getDiscounted()->getValue();
                    $cartLineItem->priceOld = (string)$price->getValue();
                } else {
                    $cartLineItem->price = (string)$price->getValue();
                }
                $cartLineItem->totalPrice = $lineItem->getTotalPrice();
                $cartLineItem->attributes = new ViewDataCollection();

                $cartAttributes = $this->config['sunrise.cart.attributes'];
                foreach ($cartAttributes as $attributeName) {
                    $attribute = $variant->getAttributes()->getByName($attributeName);
                    if ($attribute) {
                        $lineItemAttribute = new ViewData();
                        $lineItemAttribute->label = $attributeName;
                        $lineItemAttribute->key = $attributeName;
                        $lineItemAttribute->value = (string)$attribute->getValue();
                        $cartLineItem->attributes->add($lineItemAttribute);
                    }
                }
                $cartItems->list->add($cartLineItem);
            }
        }

        return $cartItems;
    }
}
