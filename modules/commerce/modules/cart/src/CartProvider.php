<?php

namespace Drupal\commerce_cart;

use Drupal\commerce_cart\Exception\DuplicateCartException;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Default implementation of the cart provider.
 */
class CartProvider implements CartProviderInterface {

  /**
   * The order storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $orderStorage;

  /**
   * The current store.
   *
   * @var \Drupal\commerce_store\CurrentStoreInterface
   */
  protected $currentStore;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The session.
   *
   * @var \Drupal\commerce_cart\CartSessionInterface
   */
  protected $cartSession;

  /**
   * The loaded cart data, grouped first by uid, then by store ID, and finally
   * keyed by cart order ID.
   *
   * Each data item is an array with the following keys:
   * - type: The order type.
   *
   * @var array
   */
  protected $cartData = [];

  /**
   * Constructs a new CartProvider object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_store\CurrentStoreInterface $current_store
   *   The current store.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\commerce_cart\CartSessionInterface $cart_session
   *   The cart session.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, CurrentStoreInterface $current_store, AccountInterface $current_user, CartSessionInterface $cart_session) {
    $this->orderStorage = $entity_type_manager->getStorage('commerce_order');
    $this->currentStore = $current_store;
    $this->currentUser = $current_user;
    $this->cartSession = $cart_session;
  }

  /**
   * {@inheritdoc}
   */
  public function createCart($order_type, StoreInterface $store = NULL, AccountInterface $account = NULL) {
    $store = $store ?: $this->currentStore->getStore();
    $account = $account ?: $this->currentUser;
    $uid = $account->id();
    $store_id = $store->id();
    if ($this->getCartId($order_type, $store, $account)) {
      // Don't allow multiple cart orders matching the same criteria.
      throw new DuplicateCartException("A cart order for type '$order_type', store '$store_id' and account '$uid' already exists.");
    }

    // Create the new cart order.
    $cart = $this->orderStorage->create([
      'type' => $order_type,
      'store_id' => $store_id,
      'uid' => $uid,
      'cart' => TRUE,
    ]);
    $cart->save();
    // Store the new cart order id in the anonymous user's session so that it
    // can be retrieved on the next page load.
    if ($account->isAnonymous()) {
      $this->cartSession->addCartId($cart->id());
    }
    // Cart data has already been loaded, add the new cart order to the list.
    if (isset($this->cartData[$uid][$store_id])) {
      $this->cartData[$uid][$store_id][$cart->id()] = [
        'type' => $order_type,
      ];
    }

    return $cart;
  }

  /**
   * {@inheritdoc}
   */
  public function finalizeCart(OrderInterface $cart, $save_cart = TRUE) {
    $cart->cart = FALSE;
    if ($save_cart) {
      $cart->save();
    }
    // The cart is anonymous, move it to the 'completed' session.
    if (!$cart->getCustomerId()) {
      $this->cartSession->deleteCartId($cart->id(), CartSession::ACTIVE);
      $this->cartSession->addCartId($cart->id(), CartSession::COMPLETED);
    }
    $store_id = $cart->getStoreId();
    // Remove the cart order from the internal cache, if present.
    if (isset($this->cartData[$cart->getCustomerId()][$store_id][$cart->id()])) {
      unset($this->cartData[$cart->getCustomerId()][$store_id][$cart->id()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCart($order_type, StoreInterface $store = NULL, AccountInterface $account = NULL) {
    $cart = NULL;
    $cart_id = $this->getCartId($order_type, $store, $account);
    if ($cart_id) {
      $cart = $this->orderStorage->load($cart_id);
    }

    return $cart;
  }

  /**
   * {@inheritdoc}
   */
  public function getCartId($order_type, StoreInterface $store = NULL, AccountInterface $account = NULL) {
    $cart_id = NULL;
    $cart_data = $this->loadCartData($account, $store);
    if ($cart_data) {
      $search = [
        'type' => $order_type,
      ];
      $cart_id = array_search($search, $cart_data);
    }

    return $cart_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getCarts(AccountInterface $account = NULL, StoreInterface $store = NULL) {
    $carts = [];
    $cart_ids = $this->getCartIds($account, $store);
    if ($cart_ids) {
      $carts = $this->orderStorage->loadMultiple($cart_ids);
    }

    return $carts;
  }

  /**
   * {@inheritdoc}
   */
  public function getCartIds(AccountInterface $account = NULL, StoreInterface $store = NULL) {
    $cart_data = $this->loadCartData($account, $store);
    return array_keys($cart_data);
  }

  /**
   * {@inheritdoc}
   */
  public function clearCaches() {
    $this->cartData = [];
  }

  /**
   * Loads the cart data for the given user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user. If empty, the current user is assumed.
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store. If empty, the current store is assumed.
   *
   * @return array
   *   The cart data.
   */
  protected function loadCartData(AccountInterface $account = NULL, StoreInterface $store = NULL) {
    $store = $store ?: $this->currentStore->getStore();

    // No store was passed or resolved, stop here.
    if (!$store) {
      return [];
    }

    $account = $account ?: $this->currentUser;
    $uid = $account->id();
    $store_id = $store->id();

    if (isset($this->cartData[$uid][$store_id])) {
      return $this->cartData[$uid][$store_id];
    }

    if ($account->isAuthenticated()) {
      $query = $this->orderStorage->getQuery()
        ->condition('state', 'draft')
        ->condition('cart', TRUE)
        ->condition('uid', $account->id())
        ->condition('store_id', $store_id)
        ->sort('order_id', 'DESC')
        ->accessCheck(FALSE);
      $cart_ids = $query->execute();
    }
    else {
      $cart_ids = $this->cartSession->getCartIds();
    }

    $this->cartData[$uid][$store_id] = [];
    if (!$cart_ids) {
      return [];
    }
    // Getting the cart data and validating the cart IDs received from the
    // session requires loading the entities. This is a performance hit, but
    // it's assumed that these entities would be loaded at one point anyway.
    /** @var \Drupal\commerce_order\Entity\OrderInterface[] $carts */
    $carts = $this->orderStorage->loadMultiple($cart_ids);
    $non_eligible_cart_ids = [];
    foreach ($carts as $cart) {
      if ($cart->isLocked()) {
        // Skip locked carts, the customer is probably off-site for payment.
        continue;
      }
      // Skip non draft / non cart orders.
      if ($cart->getState()->getId() !== 'draft' || empty($cart->cart->value)) {
        $non_eligible_cart_ids[] = $cart->id();
        continue;
      }
      // Skip carts that don't belong to the given customer or the given store.
      if ($cart->getCustomerId() != $uid || $cart->getStoreId() != $store_id) {
        // Skip carts that are no longer eligible.
        $non_eligible_cart_ids[] = $cart->id();
        continue;
      }

      $this->cartData[$uid][$store_id][$cart->id()] = [
        'type' => $cart->bundle(),
      ];
    }
    // Avoid loading non-eligible carts on the next page load.
    if (!$account->isAuthenticated()) {
      foreach ($non_eligible_cart_ids as $cart_id) {
        $this->cartSession->deleteCartId($cart_id);
      }
    }

    return $this->cartData[$uid][$store_id];
  }

}
