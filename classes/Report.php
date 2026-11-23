<?php
/**
 * Report.php
 * -------------------------------------------------------
 * Read-only reporting queries: dashboard metrics, arrears
 * ageing, officer performance and collection trends.
 * -------------------------------------------------------
 */

class Report extends Model
{
    protected function getTable(): string
    {
        return 'v_loans_with_status';
    }

    /** Everything the dashboard needs, in one round trip per block. */
    public function dashboard(): array
    {
        $loan      = new Loan();
        $payment   = new Payment();
        $customer  = new Customer();

        $portfolio = $loan->portfolio();
        $customers = $loan->customerMetrics();
        $weekly    = $loan->weeklyCollections(13);
        $today     = $payment->todayTotal();
        $weekStart = date('Y-m-d', strtotime('monday this week'));

        return [
            'portfolio'        => $portfolio,
            'customers'        => $customers,
            'weekly'           => $weekly,
            'today'            => $today,
            'week_total'       => $payment->summary($weekStart, date('Y-m-d'))['total'] ?? 0,
            'month_total'      => $payment->summary(date('Y-m-01'), date('Y-m-d'))['total'] ?? 0,
            'recent_payments'  => $this->recentPayments(6),
            'top_overdue'      => $this->overdue(6),
            'due_today'        => $this->dueToday(6),
            'incomplete'       => $customer->countIncomplete(),
            'aging'            => $this->aging(),
        ];
    }

    public function recentPayments(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT v.*, u.full_name AS received_by_name
               FROM v_payments_with_balance v
               JOIN payments p ON p.id = v.payment_id
          LEFT JOIN users u ON u.id = p.received_by
              WHERE v.status = 'confirmed'
              ORDER BY v.payment_date DESC, v.payment_id DESC
              LIMIT " . max(1, min(100, $limit))
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Overdue loans, worst first. */
    public function overdue(?int $limit = null): array
    {
        $sql = "SELECT * FROM v_overdue_loans";

        if ($limit !== null) {
            $sql .= " LIMIT " . max(1, min(500, $limit));
        }

        return $this->db->query($sql)->fetchAll();
    }

    /** Loans due today. */
    public function dueToday(?int $limit = null): array
    {
        $sql = "SELECT * FROM v_loans_with_status WHERE loan_status = 'DUE' ORDER BY remaining_balance DESC";

        if ($limit !== null) {
            $sql .= " LIMIT " . max(1, min(500, $limit));
        }

        return $this->db->query($sql)->fetchAll();
    }

    /** Arrears ageing buckets by days overdue. */
    public function aging(): array
    {
        $buckets = [
            '1-7'   => ['loans' => 0, 'amount' => 0.0],
            '8-14'  => ['loans' => 0, 'amount' => 0.0],
            '15-30' => ['loans' => 0, 'amount' => 0.0],
            '31-60' => ['loans' => 0, 'amount' => 0.0],
            '60+'   => ['loans' => 0, 'amount' => 0.0],
        ];

        $rows = $this->db->query(
            "SELECT CASE
                    WHEN days_overdue <= 7 THEN '1-7'
                    WHEN days_overdue <= 14 THEN '8-14'
                    WHEN days_overdue <= 30 THEN '15-30'
                    WHEN days_overdue <= 60 THEN '31-60'
                    ELSE '60+'
                    END AS bucket,
                    COUNT(*) AS loans,
                    COALESCE(SUM(remaining_balance), 0) AS amount
               FROM v_overdue_loans
              GROUP BY bucket"
        )->fetchAll();

        foreach ($rows as $row) {
            $key = (string) $row['bucket'];
            if (isset($buckets[$key])) {
                $buckets[$key] = [
                    'loans' => (int) $row['loans'],
                    'amount' => (float) $row['amount'],
                ];
            }
        }

        return $buckets;
    }

    /** Collections per officer for a period. */
    public function officerPerformance(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.full_name, u.role,
                    COUNT(p.id) AS receipts,
                    COALESCE(SUM(p.amount), 0) AS collected,
                    COUNT(DISTINCT p.customer_id) AS customers
               FROM users u
          LEFT JOIN payments p
                 ON p.received_by = u.id
                AND p.status = 'confirmed'
                AND DATE(p.paid_at) BETWEEN :from AND :to
              WHERE u.status = 'active'
              GROUP BY u.id, u.full_name, u.role
              ORDER BY collected DESC"
        );

        $stmt->execute(['from' => $from, 'to' => $to]);

        return $stmt->fetchAll();
    }

    /** Monthly collection trend for the last N months. */
    public function monthlyTrend(int $months = 6): array
    {
        $months = max(1, min(60, $months));

        $stmt = $this->db->query(
            "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym,
                    SUM(amount) AS collected,
                    COUNT(*) AS receipts
               FROM payments
              WHERE status = 'confirmed'
                AND paid_at >= DATE_SUB(CURDATE(), INTERVAL {$months} MONTH)
              GROUP BY ym
              ORDER BY ym"
        );

        return $stmt->fetchAll();
    }

    /** Everything needed for one loan's statement. */
    public function statement(int $loanId): array|false
    {
        $loan = (new Loan())->findDetailed($loanId);

        if (!$loan) {
            return false;
        }

        return [
            'loan'      => $loan,
            'payments'  => (new Payment())->forLoan($loanId),
            'schedule'  => (new RepaymentSchedule())->forLoan($loanId),
        ];
    }

    /** Daily reconciliation data for one date + officer. */
    public function reconciliation(string $date, ?int $officerId = null): array
    {
        $payment = new Payment();
        $summary = $payment->summary($date, $date, $officerId);

        $sql = "SELECT v.*, u.full_name AS received_by_name
                  FROM v_payments_with_balance v
                  JOIN payments p ON p.id = v.payment_id
             LEFT JOIN users u ON u.id = p.received_by
                 WHERE v.status = 'confirmed' AND DATE(v.payment_date) = :d";

        $params = ['d' => $date];

        if ($officerId !== null && $officerId > 0) {
            $sql .= " AND p.received_by = :officer";
            $params['officer'] = $officerId;
        }

        $sql .= " ORDER BY v.payment_date";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return [
            'summary'  => $summary,
            'payments' => $stmt->fetchAll(),
            'date'     => $date,
        ];
    }
}
