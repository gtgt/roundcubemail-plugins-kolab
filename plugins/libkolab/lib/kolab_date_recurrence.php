<?php

/**
 * Recurrence computation class for xcal-based Kolab format objects
 *
 * Uitility class to compute instances of recurring events.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
class kolab_date_recurrence
{
    private $engine;
    private $object;
    private $next;
    private $duration;
    private $tz_offset = 0;
    private $dst_start = 0;
    private $allday = false;
    private $hour = 0;

    /**
     * Default constructor
     *
     * @param array The Kolab object to operate on
     */
    function __construct($event)
    {
        $this->object = $object = $event->to_array();

        // shift dates to user's timezone to correctly compute times across DST changes
        $object['start']->setTimezone(kolab_format::$timezone);
        $object['end']->setTimezone(kolab_format::$timezone);

        $this->next = new Horde_Date($object['start'], kolab_format::$timezone->getName());

        if (is_object($object['start']) && is_object($object['end']))
            $this->duration = $object['start']->diff($object['end']);
        else
            $this->duration = new DateInterval('PT' . ($object['end'] - $object['start']) . 'S');

        // use (copied) Horde classes to compute recurring instances
        // TODO: replace with something that has less than 6'000 lines of code
        $this->engine = new Horde_Date_Recurrence($this->next);
        $this->engine->fromRRule20($this->to_rrule($object['recurrence']));  // TODO: get that string directly from libkolabxml

        foreach ((array)$object['recurrence']['EXDATE'] as $exdate)
            $this->engine->addException($exdate->format('Y'), $exdate->format('n'), $exdate->format('j'));

        $now = new DateTime('now', kolab_format::$timezone);
        $this->tz_offset = $object['allday'] ? $now->getOffset() - date('Z') : 0;
        $this->dst_start = $this->next->format('I');
        $this->allday = $object['allday'];
        $this->hour = $this->next->hour;
    }

    /**
     * Get date/time of the next occurence of this event
     *
     * @param boolean Return a Unix timestamp instead of a DateTime object
     * @return mixed  DateTime object/unix timestamp or False if recurrence ended
     */
    public function next_start($timestamp = false)
    {
        $time = false;
        $after = clone $this->next;
        $after->mday = $after->mday + 1;
        if ($this->next && ($next = $this->engine->nextActiveRecurrence($after))) {
            if (!$next->after($this->next)) {
                // avoid endless loops if recurrence computation fails
                return false;
            }
            if ($this->allday) {
                $next->hour = $this->hour;  # fix time for all-day events
                $next->min = 0;
            }
            if ($timestamp) {
                # consider difference in daylight saving between base event and recurring instance
                $dst_diff = ($this->dst_start - $next->format('I')) * 3600;
                $time = $next->timestamp() - $this->tz_offset - $dst_diff;
            }
            else {
                $time = $next->toDateTime();
            }
            $this->next = $next;
        }

        return $time;
    }

    /**
     * Get the next recurring instance of this event
     *
     * @return mixed Array with event properties or False if recurrence ended
     */
    public function next_instance()
    {
        if ($next_start = $this->next_start()) {
            $next_end = clone $next_start;
            $next_end->add($this->duration);

            $next = $this->object;
            $next['recurrence_id'] = $next_start->format('Y-m-d');
            $next['start'] = $next_start;
            $next['end'] = $next_end;
            unset($next['_formatobj']);

            return $next;
        }

        return false;
    }

    /**
     * Get the end date of the occurence of this recurrence cycle
     *
     * @param string Date limit (where infinite recurrences should abort)
     * @return mixed Timestamp with end date of the last event or False if recurrence exceeds limit
     */
    public function end($limit = null)
    {
        if ($this->object['recurrence']['UNTIL'])
            return $this->object['recurrence']['UNTIL']->format('U');

        // determine a reasonable limit if none given
        if (!$limit) {
            switch ($this->object['recurrence']['FREQ']) {
                case 'YEARLY':  $intvl = 'P100Y'; break;
                case 'MONTHLY': $intvl = 'P20Y';  break;
                default:        $intvl = 'P10Y';  break;
            }

            $limit = clone $this->object['start'];
            $limit->add(new DateInterval($intvl));
        }
        else if (is_string($limit)) {
            $limit = new DateTime($limit, new DateTimeZone(kolab_format::$timezone->getName()));
        }

        while ($next_start = $this->next_start()) {
            if ($next_start > $limit)
                break;
        }

        if ($this->next) {
            $next_end = $this->next->toDateTime();
            $next_end->add($this->duration);
            return $next_end->format('U');
        }

        return false;
    }

    /**
     * Convert the internal structured data into a vcalendar RRULE 2.0 string
     */
    private function to_rrule($recurrence)
    {
      if (is_string($recurrence))
          return $recurrence;

        $rrule = '';
        foreach ((array)$recurrence as $k => $val) {
            $k = strtoupper($k);
            switch ($k) {
            case 'UNTIL':
                $val = $val->format('Ymd\THis');
                break;
            case 'EXDATE':
                foreach ((array)$val as $i => $ex)
                    $val[$i] = $ex->format('Ymd\THis');
                $val = join(',', (array)$val);
                break;
            }
            $rrule .= $k . '=' . $val . ';';
        }

      return $rrule;
    }

}
