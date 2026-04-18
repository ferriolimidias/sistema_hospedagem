<?php
declare(strict_types=1);

/**
 * Cálculo de estadia (noites + taxa por hóspede extra), alinhado com script.js.
 *
 * @param array<string,mixed> $chalet Linha de chalets + chave opcional 'holidays': lista de ['date'=>'Y-m-d','price'=>float]
 */
function pricing_count_nights(string $checkinYmd, string $checkoutYmd): int
{
    try {
        $cin = new DateTimeImmutable($checkinYmd . ' 00:00:00');
        $cout = new DateTimeImmutable($checkoutYmd . ' 00:00:00');
    } catch (Exception $e) {
        return 1;
    }
    if ($cout <= $cin) {
        return 1;
    }

    return max(1, (int) $cin->diff($cout)->days);
}

function pricing_nightly_subtotal(array $chalet, string $checkinYmd, string $checkoutYmd): float
{
    $nights = pricing_count_nights($checkinYmd, $checkoutYmd);
    $basePrice = isset($chalet['price']) ? (float) $chalet['price'] : 0.0;

    $holidaysByDate = [];
    if (!empty($chalet['holidays']) && is_array($chalet['holidays'])) {
        foreach ($chalet['holidays'] as $h) {
            if (!empty($h['date'])) {
                $holidaysByDate[(string) $h['date']] = (float) ($h['price'] ?? 0);
            }
        }
    }

    $weekProps = ['price_sun', 'price_mon', 'price_tue', 'price_wed', 'price_thu', 'price_fri', 'price_sat'];

    try {
        $cin = new DateTimeImmutable($checkinYmd);
    } catch (Exception $e) {
        return round($basePrice * $nights, 2);
    }

    $total = 0.0;
    for ($i = 0; $i < $nights; $i++) {
        $current = $cin->modify('+' . $i . ' days');
        $dateStr = $current->format('Y-m-d');
        $dow = (int) $current->format('w');
        $nightPrice = $basePrice;

        if (isset($holidaysByDate[$dateStr]) && $holidaysByDate[$dateStr] > 0) {
            $nightPrice = $holidaysByDate[$dateStr];
        } else {
            $prop = $weekProps[$dow];
            $v = isset($chalet[$prop]) && $chalet[$prop] !== null && $chalet[$prop] !== ''
                ? (float) $chalet[$prop]
                : 0.0;
            if ($v > 0) {
                $nightPrice = $v;
            }
        }
        $total += $nightPrice;
    }

    return round($total, 2);
}

/**
 * Taxa extra por noite: (total_hóspedes - base_guests) * extra_guest_fee * noites (só se exceder base).
 */
function pricing_extra_guest_subtotal(array $chalet, int $guestsAdults, int $guestsChildren, int $nights): float
{
    $baseGuests = isset($chalet['base_guests']) ? (int) $chalet['base_guests'] : 2;
    if ($baseGuests < 1) {
        $baseGuests = 1;
    }
    $fee = isset($chalet['extra_guest_fee']) ? (float) $chalet['extra_guest_fee'] : 0.0;
    if ($fee <= 0.0 || $nights < 1) {
        return 0.0;
    }
    $totalGuests = max(0, $guestsAdults) + max(0, $guestsChildren);
    $extra = max(0, $totalGuests - $baseGuests);

    return round($extra * $fee * $nights, 2);
}

function pricing_reservation_total(array $chalet, string $checkinYmd, string $checkoutYmd, int $guestsAdults, int $guestsChildren): float
{
    $nights = pricing_count_nights($checkinYmd, $checkoutYmd);
    $lodging = pricing_nightly_subtotal($chalet, $checkinYmd, $checkoutYmd);
    $extra = pricing_extra_guest_subtotal($chalet, $guestsAdults, $guestsChildren, $nights);

    return round($lodging + $extra, 2);
}
