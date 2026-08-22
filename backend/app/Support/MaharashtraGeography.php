<?php

namespace App\Support;

/**
 * Official Maharashtra district → taluka master used by seeders and APIs.
 * Keep this as the single source of truth — do not copy lists into screens.
 */
final class MaharashtraGeography
{
    public const STATE_NAME = 'Maharashtra';

    /**
     * @return list<array{name: string, former_name: ?string, talukas: list<string>}>
     */
    public static function districts(): array
    {
        return [
            ['name' => 'Ahilyanagar', 'former_name' => 'Ahmednagar', 'talukas' => [
                'Akole', 'Jamkhed', 'Karjat', 'Kopargaon', 'Nagar', 'Nevasa', 'Parner',
                'Pathardi', 'Rahta', 'Rahuri', 'Sangamner', 'Shevgaon', 'Shrigonda', 'Shrirampur',
            ]],
            ['name' => 'Akola', 'former_name' => null, 'talukas' => [
                'Akola', 'Akot', 'Balapur', 'Barshitakli', 'Murtizapur', 'Patur', 'Telhara',
            ]],
            ['name' => 'Amravati', 'former_name' => null, 'talukas' => [
                'Achalpur', 'Amravati', 'Anjangaon Surji', 'Bhatkuli', 'Chandur Railway',
                'Chandurbazar', 'Chikhaldara', 'Daryapur', 'Dhamangaon Railway', 'Dharni',
                'Morshi', 'Nandgaon-Khandeshwar', 'Teosa', 'Warud',
            ]],
            ['name' => 'Beed', 'former_name' => null, 'talukas' => [
                'Ambejogai', 'Ashti', 'Beed', 'Dharur', 'Georai', 'Kaij', 'Majalgaon',
                'Parli', 'Patoda', 'Shirur (Kasar)', 'Wadwani',
            ]],
            ['name' => 'Bhandara', 'former_name' => null, 'talukas' => [
                'Bhandara', 'Lakhandur', 'Lakhani', 'Mohadi', 'Pauni', 'Sakoli', 'Tumsar',
            ]],
            ['name' => 'Buldhana', 'former_name' => null, 'talukas' => [
                'Buldhana', 'Chikhli', 'Deulgaon Raja', 'Jalgaon Jamod', 'Khamgaon', 'Lonar',
                'Malkapur', 'Mehkar', 'Motala', 'Nandura', 'Sangrampur', 'Shegaon', 'Sindkhed Raja',
            ]],
            ['name' => 'Chandrapur', 'former_name' => null, 'talukas' => [
                'Ballarpur', 'Bhadravati', 'Brahmapuri', 'Chandrapur', 'Chimur', 'Gondpipri',
                'Jiwati', 'Korpana', 'Mul', 'Nagbhid', 'Pombhurna', 'Rajura', 'Saoli',
                'Sindewahi', 'Warora',
            ]],
            ['name' => 'Chhatrapati Sambhajinagar', 'former_name' => 'Aurangabad', 'talukas' => [
                'Chhatrapati Sambhajinagar', 'Gangapur', 'Kannad', 'Khuldabad', 'Paithan',
                'Phulambri', 'Sillod', 'Soegaon', 'Vaijapur',
            ]],
            ['name' => 'Dharashiv', 'former_name' => 'Osmanabad', 'talukas' => [
                'Bhum', 'Dharashiv', 'Kalamb', 'Lohara', 'Omerga', 'Paranda', 'Tuljapur', 'Washi',
            ]],
            ['name' => 'Dhule', 'former_name' => null, 'talukas' => [
                'Dhule', 'Sakri', 'Shindkheda', 'Shirpur',
            ]],
            ['name' => 'Gadchiroli', 'former_name' => null, 'talukas' => [
                'Aheri', 'Armori', 'Bhamragad', 'Chamorshi', 'Desaiganj (Wadsa)', 'Dhanora',
                'Etapalli', 'Gadchiroli', 'Korchi', 'Kurkheda', 'Mulchera', 'Sironcha',
            ]],
            ['name' => 'Gondia', 'former_name' => null, 'talukas' => [
                'Amgaon', 'Arjuni Morgaon', 'Deori', 'Gondia', 'Goregaon', 'Sadak Arjuni',
                'Salekasa', 'Tirora',
            ]],
            ['name' => 'Hingoli', 'former_name' => null, 'talukas' => [
                'Aundha Nagnath', 'Basmath', 'Hingoli', 'Kalamnuri', 'Sengaon',
            ]],
            ['name' => 'Jalgaon', 'former_name' => null, 'talukas' => [
                'Amalner', 'Bhadgaon', 'Bhusawal', 'Bodwad', 'Chalisgaon', 'Chopda',
                'Dharangaon', 'Erandol', 'Jalgaon', 'Jamner', 'Muktainagar', 'Pachora',
                'Parola', 'Raver', 'Yawal',
            ]],
            ['name' => 'Jalna', 'former_name' => null, 'talukas' => [
                'Ambad', 'Badnapur', 'Bhokardan', 'Ghansawangi', 'Jafferabad', 'Jalna',
                'Mantha', 'Partur',
            ]],
            ['name' => 'Kolhapur', 'former_name' => null, 'talukas' => [
                'Ajara', 'Bavda', 'Bhudargad', 'Chandgad', 'Gadhinglaj', 'Gaganbawada',
                'Hatkanangle', 'Kagal', 'Karvir', 'Panhala', 'Radhanagari', 'Shahuwadi', 'Shirol',
            ]],
            ['name' => 'Latur', 'former_name' => null, 'talukas' => [
                'Ahmadpur', 'Ausa', 'Chakur', 'Deoni', 'Jalkot', 'Latur', 'Nilanga',
                'Renapur', 'Shirur Anantpal', 'Udgir',
            ]],
            ['name' => 'Mumbai City', 'former_name' => null, 'talukas' => [
                'Mumbai City',
            ]],
            ['name' => 'Mumbai Suburban', 'former_name' => null, 'talukas' => [
                'Andheri', 'Borivali', 'Kurla',
            ]],
            ['name' => 'Nagpur', 'former_name' => null, 'talukas' => [
                'Bhiwapur', 'Hingna', 'Kalameshwar', 'Kamptee', 'Katol', 'Kuhi', 'Mauda',
                'Nagpur (Rural)', 'Nagpur (Urban)', 'Narkhed', 'Parseoni', 'Ramtek', 'Savner', 'Umred',
            ]],
            ['name' => 'Nanded', 'former_name' => null, 'talukas' => [
                'Ardhapur', 'Bhokar', 'Biloli', 'Deglur', 'Dharmabad', 'Hadgaon',
                'Himayatnagar', 'Kandhar', 'Kinwat', 'Loha', 'Mahoor', 'Mudkhed', 'Mukhed',
                'Naigaon', 'Nanded', 'Umri',
            ]],
            ['name' => 'Nandurbar', 'former_name' => null, 'talukas' => [
                'Akkalkuwa', 'Akrani (Dhadgaon)', 'Nandurbar', 'Nawapur', 'Shahada', 'Taloda',
            ]],
            ['name' => 'Nashik', 'former_name' => null, 'talukas' => [
                'Baglan', 'Chandwad', 'Deola', 'Dindori', 'Igatpuri', 'Kalwan', 'Malegaon',
                'Nandgaon', 'Nashik', 'Niphad', 'Peint', 'Sinnar', 'Surgana', 'Trimbakeshwar', 'Yevla',
            ]],
            ['name' => 'Palghar', 'former_name' => null, 'talukas' => [
                'Dahanu', 'Jawhar', 'Mokhada', 'Palghar', 'Talasari', 'Vasai', 'Vikramgad', 'Wada',
            ]],
            ['name' => 'Parbhani', 'former_name' => null, 'talukas' => [
                'Gangakhed', 'Jintur', 'Manwath', 'Palam', 'Parbhani', 'Pathri', 'Purna',
                'Sailu', 'Sonpeth',
            ]],
            ['name' => 'Pune', 'former_name' => null, 'talukas' => [
                'Ambegaon', 'Baramati', 'Bhor', 'Daund', 'Haveli', 'Indapur', 'Junnar',
                'Khed', 'Maval', 'Mulshi', 'Pune City', 'Purandar', 'Shirur', 'Velhe',
            ]],
            ['name' => 'Raigad', 'former_name' => null, 'talukas' => [
                'Alibag', 'Karjat', 'Khalapur', 'Mahad', 'Mangaon', 'Mhasla', 'Murud',
                'Panvel', 'Pen', 'Poladpur', 'Roha', 'Shrivardhan', 'Sudhagad', 'Tala', 'Uran',
            ]],
            ['name' => 'Ratnagiri', 'former_name' => null, 'talukas' => [
                'Chiplun', 'Dapoli', 'Guhagar', 'Khed', 'Lanja', 'Mandangad', 'Rajapur',
                'Ratnagiri', 'Sangameshwar',
            ]],
            ['name' => 'Sangli', 'former_name' => null, 'talukas' => [
                'Atpadi', 'Jat', 'Kadegaon', 'Kavathe Mahankal', 'Khanapur (Vita)', 'Miraj',
                'Palus', 'Shirala', 'Tasgaon', 'Walwa',
            ]],
            ['name' => 'Satara', 'former_name' => null, 'talukas' => [
                'Jaoli', 'Karad', 'Khandala', 'Khatav', 'Koregaon', 'Mahabaleshwar', 'Man',
                'Patan', 'Phaltan', 'Satara', 'Wai',
            ]],
            ['name' => 'Sindhudurg', 'former_name' => null, 'talukas' => [
                'Devgad', 'Dodamarg', 'Kankavli', 'Kudal', 'Malvan', 'Sawantwadi',
                'Vaibhavwadi', 'Vengurla',
            ]],
            ['name' => 'Solapur', 'former_name' => null, 'talukas' => [
                'Akkalkot', 'Barshi', 'Karmala', 'Madha', 'Malshiras', 'Mangalvedhe', 'Mohol',
                'Pandharpur', 'Sangole', 'Solapur North', 'Solapur South',
            ]],
            ['name' => 'Thane', 'former_name' => null, 'talukas' => [
                'Ambarnath', 'Bhiwandi', 'Kalyan', 'Murbad', 'Shahapur', 'Thane', 'Ulhasnagar',
            ]],
            ['name' => 'Wardha', 'former_name' => null, 'talukas' => [
                'Arvi', 'Ashti', 'Deoli', 'Hinganghat', 'Karanja', 'Samudrapur', 'Seloo', 'Wardha',
            ]],
            ['name' => 'Washim', 'former_name' => null, 'talukas' => [
                'Karanja', 'Malegaon', 'Mangrulpir', 'Manora', 'Risod', 'Washim',
            ]],
            ['name' => 'Yavatmal', 'former_name' => null, 'talukas' => [
                'Arni', 'Babulgaon', 'Darwha', 'Digras', 'Ghatanji', 'Kalamb', 'Kelapur',
                'Mahagaon', 'Maregaon', 'Ner', 'Pusad', 'Ralegaon', 'Umarkhed', 'Wani',
                'Yavatmal', 'Zari Jamani',
            ]],
        ];
    }

    /**
     * @return array<string, string> official name => dropdown label
     */
    public static function districtSelectOptions(?string $current = null): array
    {
        $options = [];
        foreach (self::districts() as $district) {
            $options[$district['name']] = self::districtLabel($district['name'], $district['former_name']);
        }

        if (filled($current) && self::canonicalDistrictName($current) === null) {
            $options[$current] = $current;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function talukaSelectOptions(?string $districtName, ?string $current = null): array
    {
        $talukas = self::talukasForDistrict($districtName);
        $options = $talukas === [] ? [] : array_combine($talukas, $talukas);

        if (filled($current) && self::canonicalTalukaName($districtName, $current) === null) {
            $options[$current] = $current;
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function talukasForDistrict(?string $districtName): array
    {
        $canonical = self::canonicalDistrictName($districtName);
        if ($canonical === null) {
            return [];
        }

        foreach (self::districts() as $district) {
            if ($district['name'] === $canonical) {
                return $district['talukas'];
            }
        }

        return [];
    }

    public static function canonicalDistrictName(?string $name): ?string
    {
        $needle = self::normalize($name);
        if ($needle === '') {
            return null;
        }

        foreach (self::districts() as $district) {
            if (self::normalize($district['name']) === $needle) {
                return $district['name'];
            }

            if (filled($district['former_name']) && self::normalize($district['former_name']) === $needle) {
                return $district['name'];
            }
        }

        return null;
    }

    public static function canonicalTalukaName(?string $districtName, ?string $talukaName): ?string
    {
        $needle = self::normalize($talukaName);
        if ($needle === '') {
            return null;
        }

        $talukas = self::talukasForDistrict($districtName);
        foreach ($talukas as $taluka) {
            if (self::normalize($taluka) === $needle) {
                return $taluka;
            }
        }

        $alias = self::talukaAliases()[$needle] ?? null;
        if ($alias !== null && in_array($alias, $talukas, true)) {
            return $alias;
        }

        return null;
    }

    public static function isValidState(?string $state): bool
    {
        return self::normalize($state) === self::normalize(self::STATE_NAME);
    }

    /**
     * @return array<int, mixed>
     */
    public static function stateRules(): array
    {
        return ['required', 'string', 'max:255', new \App\Rules\MaharashtraStateRule];
    }

    /**
     * @return array<int, mixed>
     */
    public static function districtRules(): array
    {
        return ['required', 'string', 'max:255', new \App\Rules\MaharashtraDistrictRule];
    }

    /**
     * @return array<int, mixed>
     */
    public static function talukaRules(): array
    {
        return ['required', 'string', 'max:255', new \App\Rules\MaharashtraTalukaRule];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function canonicalizeLocationFields(array $data): array
    {
        $data['state'] = self::STATE_NAME;
        $district = self::canonicalDistrictName(isset($data['district']) && is_string($data['district']) ? $data['district'] : null);
        if ($district !== null) {
            $data['district'] = $district;
        }

        $taluka = self::canonicalTalukaName(
            isset($data['district']) && is_string($data['district']) ? $data['district'] : null,
            isset($data['taluka']) && is_string($data['taluka']) ? $data['taluka'] : null,
        );
        if ($taluka !== null) {
            $data['taluka'] = $taluka;
        }

        return $data;
    }

    public static function districtLabel(string $name, ?string $formerName): string
    {
        return filled($formerName) ? $name.' ('.$formerName.')' : $name;
    }

    /**
     * @return array<string, string>
     */
    private static function talukaAliases(): array
    {
        return [
            'jafrabad' => 'Jafferabad',
            'aurangabad' => 'Chhatrapati Sambhajinagar',
            'osmanabad' => 'Dharashiv',
            'ahmednagar' => 'Nagar',
        ];
    }

    private static function normalize(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * @return list<string>
     */
    public static function defaultCrops(): array
    {
        return [
            'Cotton', 'Soybean', 'Maize', 'Onion', 'Grapes', 'Pomegranate',
            'Sugarcane', 'Tur', 'Chilli', 'Tomato', 'Wheat', 'Jowar', 'Bajra',
            'Groundnut', 'Banana', 'Orange', 'Mango', 'Rice', 'Chickpea',
            'Sunflower', 'Papaya', 'Guava', 'Brinjal', 'Okra', 'Cabbage',
        ];
    }
}
