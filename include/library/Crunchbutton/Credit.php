<?php

class Crunchbutton_Credit extends Cana_Table
{
	const TYPE_CREDIT = 'CREDIT';
	const TYPE_DEBIT = 'DEBIT';

	const PAID_BY_CRUNCHBUTTON = 'crunchbutton';
	const PAID_BY_RESTAURANT = 'restaurant';
	const PAID_BY_PROMOTIONAL = 'promotional';
	const PAID_BY_OTHER_RESTAURANT = 'other_restaurant';

	const CREDIT_TYPE_CASH = 'cash';
	const CREDIT_TYPE_POINT = 'point';

	public function __construct($id = null) {
		parent::__construct();
		$this
			->table('credit')
			->idVar('id_credit')
			->load($id);
	}

	public function creditByUser( $id_user ) {
		return Crunchbutton_Credit::q('SELECT * FROM credit WHERE type = ? AND id_user=? AND ( credit_type = ? OR credit_type != ? )', [self::TYPE_CREDIT, $id_user, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT]);
	}

	public function cashCreditByUser( $id_user ) {
		return Crunchbutton_Credit::q('SELECT * FROM credit WHERE type = ? AND id_user=? AND ( credit_type = ? ) ORDER BY id_credit DESC', [self::TYPE_CREDIT, $id_user, Crunchbutton_Credit::CREDIT_TYPE_CASH ]);
	}

	public function debitsFromCredit() {
		return Crunchbutton_Credit::q('SELECT * FROM credit WHERE type = ? AND id_credit_debited_from = ? ORDER BY id_credit DESC', [self::TYPE_DEBIT, $this->id_credit ]);
	}
	public function debitByUser( $id_user ) {
		return Crunchbutton_Credit::q('SELECT * FROM credit WHERE type = ? AND id_user=? AND ( credit_type = ? OR credit_type != ? )', [self::TYPE_DEBIT, $id_user, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT]);
	}

	public function creditByUserRestaurant( $id_user, $id_restaurant ) {
		if (!$id_user) {
			return 0;
		}
		$credits = 0;

		// @todo: make this faster
		$row = c::dbWrite()->get('
			SELECT SUM(`value`) as credit
			FROM (
				SELECT SUM(`value`) as `value`
				FROM credit
				WHERE
					type = ?
					AND id_user = ?
					AND id_restaurant = ?
					AND ( credit_type = ? OR credit_type != ? )
				UNION
					SELECT SUM(`value`) * -1 as `value`
					FROM credit
					WHERE
						type = ?
						AND id_user = ?
						AND id_restaurant = ?
						AND ( credit_type = ? OR credit_type != ? )
			) credit
		', [self::TYPE_CREDIT, $id_user, $id_restaurant, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT, self::TYPE_DEBIT, $id_user, $id_restaurant, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT]);

		if( $row->_items && $row->_items[0] ){
			if( $row->_items[0]->credit ){
				$credits = floatval( $row->_items[0]->credit );
			}
		}
		$anyRestaurantCredits = Crunchbutton_Credit::creditByUserValidToAnyRestaurant( $id_user );
		return floatval( $credits + $anyRestaurantCredits );
	}

	public function creditByUserValidToAnyRestaurant( $id_user ){
		if (!$id_user) {
			return 0;
		}

		// @todo: make this faster
		$row = Cana::db()->get('
			SELECT SUM(`value`) as debit
			FROM (
				SELECT SUM(`value`) as `value`
				FROM credit
				WHERE
					type = ?
					AND id_user = ?
					AND id_restaurant IS NULL
					AND ( credit_type = ? OR credit_type != ? )
				UNION
					SELECT SUM( value ) * -1
					FROM credit
					WHERE id_credit_debited_from IN (
						SELECT id_credit
						FROM credit
						WHERE
							type = ?
							AND id_user = ?
							AND id_restaurant IS NULL
							AND ( credit_type = ? OR credit_type != ? )
					)
			) debit
		', [self::TYPE_CREDIT, $id_user, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT, self::TYPE_CREDIT, $id_user, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT]);

		if( $row->_items && $row->_items[0] ){
			if( $row->_items[0]->debit ){
				return floatval( $row->_items[0]->debit );
			}
		}
		return 0;
	}

	public function totalDebitedFromCredit(){
		$row = Cana::db()->get('
			SELECT SUM(`value`) as `debited`
			FROM credit
			WHERE
				type = ?
				AND ( credit_type = ? OR credit_type != ? ) AND id_credit_debited_from = ?
		', [self::TYPE_DEBIT, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT, $this->id_credit]);
		if( $row->_items && $row->_items[0] ){
			if( $row->_items[0]->debited ){
				return floatval( $row->_items[0]->debited );
			}
		}
		return 0;
	}


	public function notifySMS() {

		$user = $this->user();

		$env = c::getEnv();

		$phone = $user->phone;

		if( !$phone ){
			return false;
		}

		$message = "Congrats, you just got a gift card added as account credit on Crunchbutton.com!";

		Crunchbutton_Message_Sms::send([
			'to' => $phone,
			'message' => $message,
			'reason' => Crunchbutton_Message_Sms::REASON_GIFT_CARD
		]);
	}

	public static function find($search = []) {

		$query = 'SELECT `credit`.* FROM `credit` LEFT JOIN restaurant USING(id_restaurant) WHERE id_credit IS NOT NULL AND ( credit_type = ? OR credit_type != ? )';
		$keys = [Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT];

		if ($search['type']) {
			$query .= ' and type=? ';
			$keys[] = $search['type'];
		}

		if ($search['start']) {
			$s = new DateTime($search['start']);
			$query .= ' and DATE(`date`)>="'.$s->format('Y-m-d').'" ';
		}

		if ($search['end']) {
			$s = new DateTime($search['end']);
			$query .= ' and DATE(`date`)<="'.$s->format('Y-m-d').'" ';
		}

		if ($search['restaurant']) {
			$query .= ' and `credit`.id_restaurant=? ';
			$keys[] = $search['restaurant'];
		}

		if ($search['id_order']) {
			$query .= ' and `credit`.id_order=? ';
			$keys[] = $search['id_order'];
		}

		if ($search['id_user']) {
			$query .= ' and `credit`.id_user=? ';
			$keys[] = $search['id_user'];
		}

		$query .= 'ORDER BY `date` DESC';

		if ($search['limit']) {
			$query .= ' limit '.$search['limit'].' ';
		}

		$credits = self::q($query, $keys);
		return $credits;
	}

	public function date() {
		if (!isset($this->_date)) {
			$this->_date = new DateTime($this->date, new DateTimeZone(c::config()->timezone));
			$this->_date->setTimezone(new DateTimeZone( c::config()->timezone ));
		}
		return $this->_date;
	}

	public static function creditByOrder( $id_order ) {
		return Crunchbutton_Credit::q('
			SELECT * FROM credit
			WHERE
				id_order=?
				AND type = ?
				AND ( credit_type IS NULL OR credit_type = ? OR credit_type != ? )
			', [$id_order, Crunchbutton_Credit::TYPE_DEBIT, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT]);
	}

	public static function creditByOrderPaidBy( $id_order, $paid_by ) {
		$query = 'SELECT SUM(`value`) as credit FROM credit WHERE id_order = ? AND type = ? AND paid_by = ?  AND ( credit_type = ? OR credit_type != ? )';
		$row = Cana::db()->get( $query, [$id_order, Crunchbutton_Credit::TYPE_DEBIT, $paid_by, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT]);
		if( $row->_items && $row->_items[0] ){
			if( $row->_items[0]->credit ){
				return $row->_items[0]->credit;
			}
		}
		return 0;
	}

	public function creditByOrderPaidByGroupped( $id_order ) {
		$query = 'SELECT SUM(`value`) as credit, paid_by FROM credit WHERE id_order = ? AND type = ? AND ( credit_type = ? OR credit_type !=? ) GROUP BY paid_by';
		$row = Cana::db()->get( $query, [$id_order, Crunchbutton_Credit::TYPE_DEBIT, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT]);
		return $row;
	}


	public function debitHistory(){
		return Crunchbutton_Credit::q('
			SELECT * FROM credit
			WHERE
				id_credit_debited_from=?
				AND ( credit_type IS NULL OR credit_type = ? OR credit_type != ? )
		'. [$this->id_credit, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT]);
	}

	public function calcDebitFromUserCredit( $valueToCharge, $id_user, $id_restaurant ){
		return Crunchbutton_Credit::debitFromUserCredit( $valueToCharge, $id_user, $id_restaurant, 0, true );
	}

	public function debitFromUserCredit( $valueToCharge, $id_user, $id_restaurant, $id_order = 0, $justCalc = false ){
		$credit = Crunchbutton_Credit::creditByUserRestaurant( $id_user, $id_restaurant );
		$totalCharged = 0;
		$charge = $valueToCharge;
		// It means the user has credit
		if( $credit > 0 ){
			$chargeLeft = $charge;
			// Now I need to share the total at the credits availabe
			$credits_available = Crunchbutton_Credit::creditsAvailableByUserRestaurant( $id_user, $id_restaurant );
			$credits_charge = array();
			if( count( $credits_available ) > 0 ){
				// Divide the same amount to each credit
				$charge_divided = Util::ceil( $charge / count( $credits_available ), 2);
				// Because the number is rounded I need to do this verification to not charge more of the user
				// if the $total is more then the $charge i just subtract the excendent from the first charge
				$total = $charge_divided * count( $credits_available );
				$first_charge = false;
				if( $total > $charge ){
					$first_charge = $charge_divided - ( $total - $charge );
				}
				foreach ($credits_available as $credit) {
					if( $first_charge ){
						$chargeOfThisCredit = $first_charge;
						$first_charge = false;
					} else {
						$chargeOfThisCredit = $charge_divided;
					}

					// returns how much left of this credit
					$left = $credit->creditLeft();

					// If the left if less than the total do charge, just charge the left.
					if( $left < $chargeOfThisCredit ){
						$chargeOfThisCredit = $left;
					}
					// Update the chargeLeft value
					$chargeLeft = $chargeLeft - $chargeOfThisCredit;
					// Populate the array
					$credits_charge[] = array( 'id_credit' => $credit->id_credit, 'charge' => $chargeOfThisCredit, 'left' => $left, 'credit' => $credit );
				}

				// If there are more to charge, lets use check again if we can use more of the user's credit
				if( $chargeLeft > 0 ){
					foreach( $credits_charge as $key => $value ){
						if( $chargeLeft <= 0 ){
							continue;
						}
						$left = $credits_charge[ $key ][ 'left' ];
						$charge = $credits_charge[ $key ][ 'charge' ];
						if( $charge < $left ){
							if( $left >= ( $charge + $chargeLeft ) ){
								$tryCharge = $charge + $chargeLeft;
								$chargeLeft = 0;
							} else {
								$tryCharge = $left;
								$chargeLeft = $left - $charge;
							}
							$credits_charge[ $key ][ 'charge' ] = $tryCharge;
						}
					}
				}
				// Finally all is calculate, let's debit the credits
				foreach( $credits_charge as $key => $value ){
					$credit = $credits_charge[ $key ][ 'credit' ];
					$charge = $credits_charge[ $key ][ 'charge' ];
					// At the first time I need just the calc, so do not charge for while
					if( !$justCalc ){
						$credit->charge( $charge, $id_order );
					}
					$totalCharged = $totalCharged + $charge;
				}
			}
		}
		return $totalCharged;
	}

	public function creditSpent(){
		$row = Cana::dbWrite()->get('
			SELECT SUM( value ) as spent
			FROM credit c
			WHERE
				type = ? AND ( credit_type = ? OR credit_type != ? ) AND id_credit_debited_from = ?
		', [Crunchbutton_Credit::TYPE_DEBIT, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT, $this->id_credit]);
		if( $row->_items && $row->_items[0] ){
				$row = $row->_items[0];
				return ( $row->spent < 0 ) ? 0 : $row->spent;
		}
		return 0;
	}

	public static function formatPoints( $points ){
		return Util::humanReadableNumbers( $points );
	}

	public static function exportPoints($user = null){
		if (!$user) {
			$user = c::user();
		}
		// Reward stuff
		$reward = new Crunchbutton_Reward;
		$reward = $reward->loadSettings();
		$free_delivery = intval( $reward[ Crunchbutton_Reward::CONFIG_KEY_MAX_CAP_POINTS ] );
		$out = [];
		$out[ 'invite_code' ] = $user->invite_code;
		$out[ 'free_delivery' ] = Crunchbutton_Credit::formatPoints( $free_delivery );
		$out[ 'total' ] = Crunchbutton_Credit::points( $user->id_user );
		$out[ 'show' ] = Crunchbutton_Credit::formatPoints( $out[ 'total' ] );
		if( $out[ 'total' ] > $free_delivery ){
			$out[ 'free_delivery_message' ] = true;
			$out[ 'away_free_delivery' ] = 0;
			$out[ 'points_percent' ] = 100;
		} else {
			$out[ 'free_delivery_message' ] = false;
			$out[ 'away_free_delivery' ] = Crunchbutton_Credit::formatPoints( $free_delivery - $out[ 'total' ] );
			$out[ 'points_percent' ] = ($out[ 'total' ] && $free_delivery) ? intval( ( $out[ 'total' ] / $free_delivery * 100 ) ) : 0;
		}
		return $out;
	}

	public static function points( $id_user ){
		$query = 'SELECT SUM( value ) AS points FROM credit c WHERE c.id_user = ? AND credit_type = ? AND type = ?';
		$row = Cana::dbWrite()->get( $query, [$id_user, Crunchbutton_Credit::CREDIT_TYPE_POINT, Crunchbutton_Credit::TYPE_CREDIT]);
		if( $row->_items && $row->_items[0] ){
				$row = $row->_items[0];
				$points = ( $row->points && $row->points < 0 ) ? 0 : $row->points;
		}

		$spent = 0;
		$query = 'SELECT SUM( value ) AS spent FROM credit c WHERE c.id_user = ? AND credit_type = ? AND type = ?';
		$row = Cana::dbWrite()->get( $query, [$id_user, Crunchbutton_Credit::CREDIT_TYPE_POINT, Crunchbutton_Credit::TYPE_DEBIT]);
		if( $row->_items && $row->_items[0] ){
				$row = $row->_items[0];
				$spent = ( $row->spent && $row->spent < 0 ) ? 0 : $row->spent;
		}
		return intval( ( $points - $spent ) );
	}

	public function creditLeft(){
		$spent = $this->creditSpent();
		return $this->value - $spent;
	}

	public function removeCreditLeft(){
		$spent = $this->creditSpent();
		$valueToRemove = $this->value - $spent;
		// Create a new debit to remove this credit
		$credit = new Crunchbutton_Credit();
		$credit->id_user = $this->id_user;
		$credit->type = Crunchbutton_Credit::TYPE_DEBIT;
		$credit->id_restaurant = $this->id_restaurant;
		$credit->date = date('Y-m-d H:i:s');
		$credit->value = $valueToRemove;
		$credit->id_order = $id_order;
		$credit->credit_type = Crunchbutton_Credit::CREDIT_TYPE_CASH;
		$credit->paid_by = $this->paid_by;
		$credit->id_restaurant_paid_by = $this->id_restaurant_paid_by;
		$credit->id_credit_debited_from = $this->id_credit;
		$credit->note = 'Removed the credit #' . $this->id_credit . ' ($' . $valueToRemove . ') - by: ' . c::admin()->name;
		$credit->save();
	}

	public function charge( $value, $id_order ){
		$credit = new Crunchbutton_Credit();
		$credit->id_user = $this->id_user;
		$credit->type = Crunchbutton_Credit::TYPE_DEBIT;
		$credit->id_restaurant = $this->id_restaurant;
		$credit->date = date('Y-m-d H:i:s');
		$credit->value = $value;
		$credit->id_order = $id_order;
		$credit->paid_by = $this->paid_by;
		$credit->credit_type = Crunchbutton_Credit::CREDIT_TYPE_CASH;
		$credit->id_restaurant_paid_by = $this->id_restaurant_paid_by;
		$credit->id_credit_debited_from = $this->id_credit;
		$credit->save();
	}

	public function creditsAvailableByUserRestaurant( $id_user, $id_restaurant ){
		$credit_available = array();
		$credits = Crunchbutton_Credit::q( 'SELECT * FROM credit WHERE type = ? AND id_user=? AND ( credit_type = ? OR credit_type != ? ) AND ( id_restaurant = ? OR id_restaurant IS NULL )', [self::TYPE_CREDIT, $id_user, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT, $id_restaurant ], c::dbWrite());
		if( $credits->count() > 0 ){
			foreach( $credits as $credit ){
				$left = $credit->creditLeft();
				if( $left > 0 ){
					$credit_available[] = $credit;
				}
			}
		}
		return $credit_available;
	}

	public function antifraudByUser( $creditMoreThan = 10 ){
		$query = "SELECT (credit - COALESCE( debits.debit, 0)) AS credit_left,
										 credits.credit,
										 COALESCE( debits.debit, 0) AS debit,
										 credits.id_user
							FROM
								(SELECT SUM(c.value) AS credit,
												u.id_user
								 FROM credit c
								 INNER JOIN `user` u ON u.id_user = c.id_user
								 WHERE c.type = 'CREDIT' AND ( credit_type = ? OR credit_type != ? )
								 GROUP BY u.id_user HAVING credit > {$creditMoreThan}) credits
							LEFT JOIN
								(SELECT SUM(c.value) AS debit,
												u.id_user
								 FROM credit c
								 INNER JOIN `user` u ON u.id_user = c.id_user
								 WHERE c.type = 'DEBIT' AND ( credit_type = ? OR credit_type != ? )
								 GROUP BY u.id_user) debits ON credits.id_user = debits.id_user HAVING credit_left > {$creditMoreThan}
							ORDER BY credit_left DESC ";
		$results = c::db()->get( $query, [Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT]);
		$credits = array();
		foreach ( $results as $result ) {
			$result->user = Crunchbutton_User::o( $result->id_user );
			$giftcards = Crunchbutton_Promo::byIdUser( $result->id_user );
			$result->giftcards = $giftcards->count();
			$credits[] = $result;
		}
		return $results;
	}

	public function antifraudByPhone( $creditMoreThan = 10 ){
		$query = "SELECT (credit - COALESCE( debits.debit, 0)) AS credit_left,
										 credits.credit,
										 COALESCE( debits.debit, 0) AS debit,
										 credits.phone
							FROM
								(SELECT SUM(c.value) AS credit,
												u.phone
								 FROM credit c
								 INNER JOIN `user` u ON u.id_user = c.id_user
								 WHERE c.type = 'CREDIT' AND ( credit_type = ? OR credit_type != ? )
									 AND u.phone IS NOT NULL
								 GROUP BY u.phone HAVING credit > {$creditMoreThan}) credits
							INNER JOIN
								(SELECT SUM(c.value) AS debit,
												u.phone
								 FROM credit c
								 INNER JOIN `user` u ON u.id_user = c.id_user
								 WHERE c.type = 'DEBIT' AND ( credit_type = ? OR credit_type != ? )
									 AND u.phone IS NOT NULL
								 GROUP BY u.phone) debits ON credits.phone = debits.phone HAVING credit_left > {$creditMoreThan}
							ORDER BY credit_left DESC";
		$results = c::db()->get( $query, [Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT]);
		$credits = array();
		foreach ( $results as $result ) {
			$result->user = Crunchbutton_User::byPhone( $result->phone );
			$giftcards = Crunchbutton_Promo::byPhone( $result->phone );
			$result->giftcards = $giftcards->count();
			$credits[] = $result;
		}
		return $results;
	}

	public function creditsByIdUser( $id_user ){
		$query = "SELECT credits.credit,
										 debits.debit,
										 (credit - debit) AS credit_left
							FROM
								(SELECT SUM(value) AS credit
								 FROM credit c
								 INNER JOIN `user` u ON u.id_user = c.id_user
								 WHERE TYPE = 'CREDIT' AND ( credit_type = ? OR credit_type != ? )
									 AND u.id_user = ?) credits,
								(SELECT SUM(value) AS debit
								 FROM credit c
								 INNER JOIN `user` u ON u.id_user = c.id_user
								 WHERE TYPE = 'DEBIT' AND ( credit_type = ? OR credit_type != ? )
									 AND u.id_user = ?) debits";
		$results = c::dbWrite()->get( $query, [Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT, $id_user, Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT, $id_user]);
		return $results->get(0);
	}

	public function totalDebitsByPhone( $phone ){
		$query = '
			SELECT SUM(value) AS debit
			FROM credit c
			INNER JOIN `user` u ON u.id_user = c.id_user
			WHERE TYPE = ? AND ( credit_type = ? OR credit_type != ?)
			AND u.phone = ?
		';
		$results = c::dbWrite()->get( $query, ['DEBIT', Crunchbutton_Credit::CREDIT_TYPE_CASH, Crunchbutton_Credit::CREDIT_TYPE_POINT, $phone]);
		return $results->get(0);
	}

	public function totalDebitsByIdUser( $id_user ){
		$query = "SELECT SUM(value) AS debit
								 FROM credit c
								 WHERE TYPE = 'DEBIT' AND ( credit_type = '" . Crunchbutton_Credit::CREDIT_TYPE_CASH . "' OR credit_type != '" . Crunchbutton_Credit::CREDIT_TYPE_POINT . "' )
									 AND id_user = '{$id_user}'";
		$results = c::dbWrite()->get( $query );
		return $results->get(0);
	}

	public function totalCreditsByIdUser( $id_user ){
		$query = "SELECT SUM(value) AS credit
								 FROM credit c
								 WHERE TYPE = 'CREDIT' AND ( credit_type = '" . Crunchbutton_Credit::CREDIT_TYPE_CASH . "' OR credit_type != '" . Crunchbutton_Credit::CREDIT_TYPE_POINT . "' )
									 AND id_user = '{$id_user}' AND id_promo IS NOT NULL";
		$results = c::dbWrite()->get( $query );
		return $results->get(0);
	}


	public function totalRefundedCreditsByIdUser( $id_user ){
		$query = "SELECT SUM(value) AS credit
								 FROM credit c
								 WHERE TYPE = 'CREDIT' AND ( credit_type = '" . Crunchbutton_Credit::CREDIT_TYPE_CASH . "' OR credit_type != '" . Crunchbutton_Credit::CREDIT_TYPE_POINT . "' )
									 AND c.id_user = '{$id_user}' AND id_promo IS NULL";
		$results = c::dbWrite()->get( $query );
		return $results->get(0);
	}

	public function totalCreditsByPhone( $phone ){
		$query = "SELECT SUM(value) AS credit
								 FROM credit c
								 INNER JOIN `user` u ON u.id_user = c.id_user
								 WHERE TYPE = 'CREDIT' AND ( credit_type = '" . Crunchbutton_Credit::CREDIT_TYPE_CASH . "' OR credit_type != '" . Crunchbutton_Credit::CREDIT_TYPE_POINT . "' )
									 AND u.phone = '{$phone}' AND c.id_promo IS NOT NULL";
		$results = c::dbWrite()->get( $query );
		return $results->get(0);
	}

	public function totalRefundedCreditsByPhone( $phone ){
		$query = "SELECT SUM(value) AS credit
								 FROM credit c
								 INNER JOIN `user` u ON u.id_user = c.id_user
								 WHERE TYPE = 'CREDIT' AND ( credit_type = '" . Crunchbutton_Credit::CREDIT_TYPE_CASH . "' OR credit_type != '" . Crunchbutton_Credit::CREDIT_TYPE_POINT . "' )
									 AND u.phone = '{$phone}' AND id_promo IS NULL";
		$results = c::dbWrite()->get( $query );
		return $results->get(0);
	}

	public function creditsByPhone( $phone ){
		$query = "SELECT credits.credit,
										 debits.debit,
										 (credit - debit) AS credit_left
							FROM
								(SELECT SUM(value) AS credit
								 FROM credit c
								 INNER JOIN `user` u ON u.id_user = c.id_user
								 WHERE TYPE = 'CREDIT' AND ( credit_type = '" . Crunchbutton_Credit::CREDIT_TYPE_CASH . "' OR credit_type != '" . Crunchbutton_Credit::CREDIT_TYPE_POINT . "' )
									 AND u.phone = '{$phone}') credits,
								(SELECT SUM(value) AS debit
								 FROM credit c
								 INNER JOIN `user` u ON u.id_user = c.id_user
								 WHERE TYPE = 'DEBIT' AND ( credit_type = '" . Crunchbutton_Credit::CREDIT_TYPE_CASH . "' OR credit_type != '" . Crunchbutton_Credit::CREDIT_TYPE_POINT . "' )
									 AND u.phone = '{$phone}') debits";
		$results = c::dbWrite()->get( $query );
		return $results->get(0);
	}

	public function user() {
		return User::o($this->id_user);
	}

	public function userFROM() {
		return User::o($this->id_user_from);
	}

	public function order() {
		return Order::o($this->id_order);
	}

	public function order_reference(){
		return Order::o($this->id_order_reference);
	}

	public function promo() {
		return Promo::o($this->id_promo);
	}

	public function restaurant() {
		return Restaurant::o($this->id_restaurant);
	}

	public function restaurant_paid_by() {
		return Restaurant::o($this->id_restaurant_paid_by);
	}
}