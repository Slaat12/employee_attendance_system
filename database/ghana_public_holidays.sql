-- Ghana public holiday calendar for leave calculations.
-- Run once in the Neon SQL Editor before using the updated leave pages.
CREATE TABLE IF NOT EXISTS public_holidays (
    holiday_id BIGSERIAL PRIMARY KEY,
    holiday_date DATE NOT NULL UNIQUE,
    holiday_name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Ghana public holidays and announced observed days for 2026.
-- Eid dates and substitute days should always be checked against Ministry of the Interior notices.
INSERT INTO public_holidays (holiday_date, holiday_name) VALUES
('2026-01-01', 'New Year''s Day'),
('2026-01-07', 'Constitution Day'),
('2026-03-06', 'Independence Day'),
('2026-03-20', 'Eid-ul-Fitr'),
('2026-03-21', 'Shaqq Day'),
('2026-03-23', 'Shaqq Day observed day'),
('2026-04-03', 'Good Friday'),
('2026-04-06', 'Easter Monday'),
('2026-05-01', 'May Day'),
('2026-05-27', 'Eid-ul-Adha'),
('2026-07-01', 'Republic Day'),
('2026-09-21', 'Founders'' Day'),
('2026-12-04', 'Farmers'' Day'),
('2026-12-25', 'Christmas Day'),
('2026-12-26', 'Boxing Day'),
('2026-12-28', 'Boxing Day observed day')
ON CONFLICT (holiday_date) DO UPDATE
SET holiday_name = EXCLUDED.holiday_name;

-- Recalculate all existing leave applications using weekdays minus public holidays.
UPDATE leave_requests lr
SET working_days = (
    SELECT COUNT(*)::INTEGER
    FROM generate_series(lr.start_date, lr.end_date, INTERVAL '1 day') AS day
    WHERE EXTRACT(ISODOW FROM day) BETWEEN 1 AND 5
      AND NOT EXISTS (
          SELECT 1 FROM public_holidays ph
          WHERE ph.holiday_date = day::date
      )
);
