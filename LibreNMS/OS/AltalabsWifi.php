<?php
/**
 * AltalabsWifi.php
 *
 * Alta Labs Wireless APs
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2024 Alta, Inc.
 * @author     Chris Buechler <chris@alta.inc>
 */

namespace LibreNMS\OS;

use App\Models\Device;
use LibreNMS\Device\WirelessSensor;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessClientsDiscovery;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessFrequencyDiscovery;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessUtilizationDiscovery;
use LibreNMS\Interfaces\Polling\Sensors\WirelessFrequencyPolling;
use LibreNMS\OS;

class AltalabsWifi extends OS implements
    WirelessClientsDiscovery,
    WirelessFrequencyDiscovery,
    WirelessFrequencyPolling,
    WirelessUtilizationDiscovery
{
    /**
     * Returns an array of LibreNMS\Device\Sensor objects
     *
     * @return array Sensors
     */
    public function discoverWirelessClients()
    {
        $client_oids = snmpwalk_cache_oid($this->getDeviceArray(), 'wlanVapStaCount', [], 'ALTA-WIRELESS-MIB');
        if (empty($client_oids)) {
            return [];
        }
        $vap_radios = $this->getCacheByIndex('wlanVapBand', 'ALTA-WIRELESS-MIB');
        $ssid_ids = $this->getCacheByIndex('wlanVapSsid', 'ALTA-WIRELESS-MIB');

        $radios = [];
        foreach ($client_oids as $index => $entry) {
            $radio_name = $vap_radios[$index] . "G";
            $radios[$radio_name]['oids'][] = '.1.3.6.1.4.1.61802.1.1.2.1.9.' . $index;
            if (isset($radios[$radio_name]['count'])) {
                $radios[$radio_name]['count'] += $entry['wlanVapStaCount'];
            } else {
                $radios[$radio_name]['count'] = $entry['wlanVapStaCount'];
            }
        }

        $sensors = [];

        // discover client counts by radio
        foreach ($radios as $name => $data) {
            $sensors[] = new WirelessSensor(
                'clients',
                $this->getDeviceId(),
                $data['oids'],
                'altalabs-wifi',
                $name,
                "Clients ({$name})",
                $data['count'],
                1,
                1,
                'sum',
                null,
                100,
                null,
                90
            );
        }

        // discover client counts by SSID
        $ssids = [];
        foreach ($client_oids as $index => $entry) {
            $ssid = $ssid_ids[$index];
            if (! empty($ssid)) {
                if (isset($ssids[$ssid])) {
                    // .1.3.6.1.4.1.61802.1.1.2.1.9 = wlanVapStaCount
                    $ssids[$ssid]['oids'][] = '.1.3.6.1.4.1.61802.1.1.2.1.9.' . $index;
                    $ssids[$ssid]['count'] += $entry['wlanVapStaCount'];
                } else {
                    $ssids[$ssid] = [
                        'oids' => ['.1.3.6.1.4.1.61802.1.1.2.1.9.' . $index],
                        'count' => $entry['wlanVapStaCount'],
                    ];
                }
            }
        }

        foreach ($ssids as $ssid => $data) {
            $sensors[] = new WirelessSensor(
                'clients',
                $this->getDeviceId(),
                $data['oids'],
                'altalabs-wifi',
                $ssid,
                'SSID: ' . $ssid,
                $data['count']
            );
        }

        return $sensors;
    }

    /**
     * Discover wireless frequency.  This is in MHz. Type is frequency.
     * Returns an array of LibreNMS\Device\Sensor objects that have been discovered
     *
     * @return array Sensors
     */
    public function discoverWirelessFrequency()
    {
        $data = snmpwalk_cache_oid($this->getDeviceArray(), 'wlanRadioChannel', [], 'ALTA-WIRELESS-MIB');
        $vap_radios = $this->getCacheByIndex('wlanRadioBand', 'ALTA-WIRELESS-MIB');

        $sensors = [];
        foreach ($data as $index => $entry) {
            $radio = $vap_radios[$index] . "G";
            if (isset($sensors[$radio])) {
                continue;
            }
            $sensors[$radio] = new WirelessSensor(
                'frequency',
                $this->getDeviceId(),
                '.1.3.6.1.4.1.61802.1.1.1.1.4.' . $index,
                'altalabs-wifi',
                $radio,
                "Frequency ($radio)",
                WirelessSensor::channelToFrequency($entry['wlanRadioChannel'])
            );
        }

        return $sensors;
    }

    /**
     * Poll wireless frequency as MHz
     * The returned array should be sensor_id => value pairs
     *
     * @param  array  $sensors  Array of sensors needed to be polled
     * @return array of polled data
     */
    public function pollWirelessFrequency(array $sensors)
    {
        return $this->pollWirelessChannelAsFrequency($sensors);
    }

    /**
     * Discover wireless utilization.  This is in %. Type is utilization.
     * Returns an array of LibreNMS\Device\Sensor objects that have been discovered
     *
     * @return array Sensors
     */
    public function discoverWirelessUtilization()
    {
        $util_oids = snmpwalk_cache_oid($this->getDeviceArray(), 'wlanRadioChanUtilization', [], 'ALTA-WIRELESS-MIB');
        if (empty($util_oids)) {
            return [];
        }
        $radio_names = $this->getCacheByIndex('wlanRadioBand', 'ALTA-WIRELESS-MIB');

        $sensors = [];
        foreach ($radio_names as $index => $name) {
            $sensors[] = new WirelessSensor(
                'utilization',
                $this->getDeviceId(),
                '.1.3.6.1.4.1.61802.1.1.1.1.5.' . $index,
                'altalabs-total-util',
                $index,
                "Total Util ({$name}G)",
                $util_oids[$index]['wlanRadioChanUtilization']
            );
        }

        return $sensors;
    }
}
