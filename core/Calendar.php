<?php
namespace EzMvc;

/**
 * A small Gregorian calendar/date helper: build from a Unix timestamp, a
 * date string (via strtotime()), or now; read/write individual fields —
 * year, month, day, hour, minute, second — through named getters/setters/
 * adders (getYears()/setYears()/addYears();
 * setters and adders return $this for chaining). Format the
 * result back out as a string (toDateString()) or ISO 8601
 * (toIso8601String()).
 */
class Calendar {

    /** date()-compatible format strings for toDateString()/toIso8601String(). */
    const FORMAT_DATETIME         = 'Y-m-d H:i:s';
    const FORMAT_DATETIME_ISO     = 'Y-m-d\TH:i:sP';
    const FORMAT_DATETIME_ISO_UTC = 'Y-m-d\TH:i:s\Z';
    const FORMAT_DATE             = 'Y-m-d';
    const FORMAT_TIME             = 'H:i:s';

    /** $data indices */
    private const FIELD_YEAR   = 0;
    private const FIELD_MONTH  = 1;
    private const FIELD_DAY    = 2;
    private const FIELD_HOUR   = 3;
    private const FIELD_MINUTE = 4;
    private const FIELD_SECOND = 5;

    /**
     * [name, min, max, seconds per unit] per FIELD_* constant. set() validates
     * against the plain range, which is not calendar-aware — setDays(31) is
     * accepted even in a month that doesn't have 31 days. add() moves the
     * fixed-length units by their seconds; years and months have none.
     */
    private const FIELDS = [
        self::FIELD_YEAR   => ['year', 1, PHP_INT_MAX, null],
        self::FIELD_MONTH  => ['month', 1, 12, null],
        self::FIELD_DAY    => ['day', 1, 31, 86400],
        self::FIELD_HOUR   => ['hour', 0, 23, 3600],
        self::FIELD_MINUTE => ['minute', 0, 59, 60],
        self::FIELD_SECOND => ['second', 0, 59, 1],
    ];

    private array $data;

    /**
     * Constructs a new Calendar object.
     * @param int|string|null $time Unix timestamp, a date string (parsed via
     *   strtotime() — '2026-01-01', 'next monday', etc.), or null for now.
     */
    public function __construct(int | string | null $time = null) {
        $this->setTime($time ?? time());
    }

    /**
     * Sets the given time, replacing whatever the instance currently holds.
     * @param int|string|null $time Unix timestamp, a date string (parsed via
     *   strtotime()), or null for now.
     * @return self
     */
    public function setTime(int | string | null $time): self {
        if (is_string($time)) {
            $time = strtotime($time);
        }

        $this->data = explode(';', date('Y;n;j;G;i;s', $time ?? time()));
        return $this;
    }
    /**
     * Gets the current time of the class as a Unix timestamp.
     * @return int
     */
    public function getTime(): int {
        return strtotime(sprintf('%04d-%02d-%02d %02d:%02d:%02d', ...$this->data));
    }

    /**
     * Formats the current date/time as a string.
     * @param string $format Any date() format string; defaults to FORMAT_DATETIME.
     * @return string
     */
    public function toDateString(string $format = self::FORMAT_DATETIME): string {
        return date($format, $this->getTime());
    }

    /**
     * Full ISO 8601 datetime string — local with a UTC offset, or UTC with
     * a 'Z' suffix if $utc is true.
     * @param bool $utc
     * @return string
     */
    public function toIso8601String(bool $utc = false): string {
        $time = $this->getTime();
        return $utc
            ? gmdate(self::FORMAT_DATETIME_ISO_UTC, $time)
            : date(self::FORMAT_DATETIME_ISO, $time);
    }

    /** Gets the year. */
    public function getYears(): int {
        return $this->get(self::FIELD_YEAR);
    }

    /**
     * Sets the year (1 or greater).
     * @throws \InvalidArgumentException if out of range
     */
    public function setYears(int $value): self {
        return $this->set(self::FIELD_YEAR, $value);
    }

    /**
     * Adds $years to the current year. Doesn't clamp the day: Feb 29 + 1
     * year onto a non-leap year overshoots forward to Mar 1, it doesn't
     * become Feb 28. Intentional — see addMonths().
     */
    public function addYears(int $years): self {
        return $this->add(self::FIELD_YEAR, $years);
    }

    /** Gets the month (1-12). */
    public function getMonths(): int {
        return $this->get(self::FIELD_MONTH);
    }

    /**
     * Sets the month (1-12).
     * @throws \InvalidArgumentException if out of range
     */
    public function setMonths(int $value): self {
        return $this->set(self::FIELD_MONTH, $value);
    }

    /**
     * Adds $months to the current month, rolling over into the year as
     * needed. Doesn't clamp the day to the target month's length: Jan 31 +
     * 1 month overshoots forward to Mar 3, it doesn't become Feb 28.
     * Intentional, not a bug — the month/year rollover here never touches
     * the day field, so an invalid resulting date (e.g. "Feb 31") is left
     * for getTime()'s date-string parsing to normalize the same way
     * strtotime() always does with an out-of-range day.
     */
    public function addMonths(int $months): self {
        return $this->add(self::FIELD_MONTH, $months);
    }

    /** Gets the day of the month (1-31). */
    public function getDays(): int {
        return $this->get(self::FIELD_DAY);
    }

    /**
     * Sets the day of the month (1-31, not checked against the actual month).
     * @throws \InvalidArgumentException if out of range
     */
    public function setDays(int $value): self {
        return $this->set(self::FIELD_DAY, $value);
    }

    /** Adds $days to the current date, rolling over into month/year as needed. */
    public function addDays(int $days): self {
        return $this->add(self::FIELD_DAY, $days);
    }

    /** Gets the hour (0-23). */
    public function getHours(): int {
        return $this->get(self::FIELD_HOUR);
    }

    /**
     * Sets the hour (0-23).
     * @throws \InvalidArgumentException if out of range
     */
    public function setHours(int $value): self {
        return $this->set(self::FIELD_HOUR, $value);
    }

    /** Adds $hours to the current time, rolling over into the day as needed. */
    public function addHours(int $hours): self {
        return $this->add(self::FIELD_HOUR, $hours);
    }

    /** Gets the minute (0-59). */
    public function getMinutes(): int {
        return $this->get(self::FIELD_MINUTE);
    }

    /**
     * Sets the minute (0-59).
     * @throws \InvalidArgumentException if out of range
     */
    public function setMinutes(int $value): self {
        return $this->set(self::FIELD_MINUTE, $value);
    }

    /** Adds $mins minutes to the current time, rolling over into the hour as needed. */
    public function addMinutes(int $mins): self {
        return $this->add(self::FIELD_MINUTE, $mins);
    }

    /** Gets the second (0-59). */
    public function getSeconds(): int {
        return $this->get(self::FIELD_SECOND);
    }

    /**
     * Sets the second (0-59).
     * @throws \InvalidArgumentException if out of range
     */
    public function setSeconds(int $value): self {
        return $this->set(self::FIELD_SECOND, $value);
    }

    /** Adds $seconds to the current time, rolling over into the minute as needed. */
    public function addSeconds(int $seconds): self {
        return $this->add(self::FIELD_SECOND, $seconds);
    }

    /**
     * Gets the day of the week as an ISO-8601 weekday number (1 = Monday
     * through 7 = Sunday).
     * @return int
     */
    public function getDayOfWeek(): int {
        return (int) date('N', $this->getTime());
    }

    /** Reads one FIELD_* value; $data holds raw explode() strings, hence the cast. */
    private function get(int $field): int {
        return (int) $this->data[$field];
    }

    /**
     * Internal engine behind setYears()/setMonths()/etc. Throws on an
     * out-of-range $value rather than wrapping it (setMinutes(75) rejects
     * 75, it doesn't become 15) — set() means "put the calendar in exactly
     * this state," so an invalid value is a caller mistake to surface.
     * @param int $field
     * @param int $value
     * @return self
     * @throws \InvalidArgumentException if $value is out of range for $field
     */
    private function set(int $field, int $value): self {
        [$name, $min, $max] = self::FIELDS[$field];
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException("$name must be between $min and $max, got $value");
        }

        $this->data[$field] = $value;
        return $this;
    }

    /**
     * Internal engine behind addYears()/addMonths()/etc. Unlike set(), never
     * throws — rollover is the point (addMinutes(75) carries 15 minutes into
     * the hour field). Months carry into the year; the fixed-length units
     * convert the delta to seconds and re-derive every field via setTime().
     * @param int $field
     * @param int $value
     * @return Calendar
     */
    private function add(int $field, int $value): self {
        if (! $value) {
            return $this;
        }
        if ($field === self::FIELD_YEAR) {
            $this->data[0] += $value;
        } elseif ($field === self::FIELD_MONTH) {
            $monthIndex    = $this->data[0] * 12 + $this->data[1] - 1 + $value;
            $this->data[0] = intdiv($monthIndex, 12);
            $this->data[1] = $monthIndex % 12 + 1;
        } else {
            $this->setTime($this->getTime() + $value * self::FIELDS[$field][3]);
        }
        return $this;
    }
}
