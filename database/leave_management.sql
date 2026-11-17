-- Leave Management setup
-- Run this file once in the Neon SQL Editor.
-- Annual leave entitlement is initialized to 15 working days for existing employees.
-- Administrators can adjust each employee's entitlement in the Leave Management page.

CREATE TABLE IF NOT EXISTS leave_balances (
    leave_balance_id BIGSERIAL PRIMARY KEY,
    employee_id INTEGER NOT NULL REFERENCES employees(employee_id) ON DELETE CASCADE,
    leave_year INTEGER NOT NULL,
    entitlement_days INTEGER NOT NULL DEFAULT 15 CHECK (entitlement_days >= 0 AND entitlement_days <= 365),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (employee_id, leave_year)
);

CREATE TABLE IF NOT EXISTS leave_requests (
    leave_request_id BIGSERIAL PRIMARY KEY,
    employee_id INTEGER NOT NULL REFERENCES employees(employee_id) ON DELETE CASCADE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    working_days INTEGER NOT NULL CHECK (working_days > 0),
    reason TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
    admin_note TEXT,
    reviewed_by INTEGER REFERENCES administrators(admin_id) ON DELETE SET NULL,
    reviewed_at TIMESTAMP WITHOUT TIME ZONE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (end_date >= start_date)
);

CREATE INDEX IF NOT EXISTS idx_leave_requests_employee
    ON leave_requests(employee_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_leave_requests_status
    ON leave_requests(status, start_date);

-- Give each existing employee a default annual entitlement for the current year.
INSERT INTO leave_balances (employee_id, leave_year, entitlement_days)
SELECT employee_id, EXTRACT(YEAR FROM CURRENT_DATE)::INTEGER, 15
FROM employees
ON CONFLICT (employee_id, leave_year) DO NOTHING;
