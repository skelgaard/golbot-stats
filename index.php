<?php
/* @var $config array */
require 'config.php';
$pokemondataurl = 'https://raw.githubusercontent.com/WatWowMap/Masterfile-Generator/master/master-latest.json';

$dsn = "mysql:host=".$config['server'].";port=".$config['port'].";dbname=".$config['database_name'].";charset=".$config['charset'];
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
    $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
$events = [
    'yest' => [
       'name' =>'Yesterday',
       'datefrom' => date('Y-m-d',strtotime('-1day')),
       'dateto' => date('Y-m-d',strtotime('-1day')),
        'alwaysshow' => true,
    ],
    'week' => [
        'name' =>'Week',
        'datefrom' => date('Y-m-d',strtotime('monday this week')),
        'dateto' => date('Y-m-d',strtotime('sunday this week')),
        'alwaysshow' => true,
    ],
    'month' => [
        'name' =>'Month',
        'datefrom' => date('Y-m-1'),
        'dateto' => date('Y-m-t'),
        'alwaysshow' => true,
    ],
];
if (!empty($config['events'])) {
    $events += $config['events'];
}
if (!is_file(basename($pokemondataurl)) or filemtime(basename($pokemondataurl)) <  strtotime('-24 hour')) {
    $pokemondata = file_get_contents($pokemondataurl);
    file_put_contents(basename($pokemondataurl),$pokemondata);
} else {
    $pokemondata = file_get_contents(basename($pokemondataurl));
}
$data = json_decode($pokemondata,true);
$today = date('Y-m-d');
$prepare = [];
$query = "SELECT i.pokemon_id,
s.total AS pokemoncount,
s.`count` AS shiny,
(SELECT SUM(h.`count`) FROM pokemon_hundo_stats h WHERE i.pokemon_id=h.pokemon_id AND i.date=h.date) AS hundo,
(SELECT SUM(n.`count`) FROM pokemon_nundo_stats n WHERE i.pokemon_id=n.pokemon_id AND i.date=n.date) AS nundo
FROM pokemon_stats i
LEFT JOIN pokemon_shiny_stats s ON i.pokemon_id=s.pokemon_id AND i.date=s.date
WHERE 1
";
if (!empty($_REQUEST['e']) and in_array($_REQUEST['e'],array_keys($events))) {
   if (!empty($events[$_REQUEST['e']]['datefrom'])) {
       $query .= "AND i.date >= :from ";
       $prepare['from'] = $events[$_REQUEST['e']]['datefrom'];
   }
    if (!empty($events[$_REQUEST['e']]['dateto'])) {
        $query .= "AND i.date <= :to ";
        $prepare['to'] = $events[$_REQUEST['e']]['dateto'];
    }
    if (!empty($events[$_REQUEST['e']]['ids'])) {
        $query .= "AND i.pokemon_id IN (". implode(',',$events[$_REQUEST['e']]['ids']) .") ";
    }
} else {
    $query .= "AND i.date = :today ";
    $prepare['today'] = $today;
}
$query .= "
GROUP BY i.pokemon_id
ORDER BY pokemoncount DESC
";
$q = $pdo->prepare($query);
$q->execute($prepare);
$all = $q->fetchAll(PDO::FETCH_ASSOC);
$total_mons = array_sum(array_column($all, 'pokemoncount'));
$total_shiny = array_sum(array_column($all, 'shiny'));
$total_hundo = array_sum(array_column($all, 'hundo'));
$total_nundo = array_sum(array_column($all, 'nundo'));
$html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
	<meta http-equiv="refresh" content="600">
    <link rel="stylesheet" href="./styles.css" />
    <title>Live shiny stats for Pokémon Go</title>
</head>
<body class="dark-changer ' . (!empty($_COOKIE["theme"]) ? "dark" : "") . '">
    <label class="switch">
        <input type="checkbox" id="toggleTheme" ' . (!empty($_COOKIE["theme"]) ? "checked" : "unchecked") . '>
        <span class="slider round"></span>
    </label>
    <div class="right" style="padding-right: 10px;">
    '. date('H:i:s') .'
    </div>
    <div class="headandstats">
	<div id="header">
		Live Stats for Pokémon Go
	</div>
  	<div class="data_period">
';
if (!empty($events)) {
    $html .= 'Other stats:<br>';
    $eventdata = '';
    if (!empty($_REQUEST['e'])) {
        $eventdata .= '<a href="?">Live</a>';
    }
    foreach ($events as $key => $event) {
        if ((!empty($_REQUEST['e']) AND $_REQUEST['e'] == $key) or empty($event['datefrom']) or empty($event['dateto'])) { continue;}
        $now = new DateTime();
        $start = new DateTime($event['datefrom']);
        $end = new DateTime($event['dateto']);
        $end->modify('+'. ($config['keep_events_days'] ?? 1).' day');

        if ($now >= $start && $now < $end or !empty($event['alwaysshow'])) {
            if (!empty($eventdata)) {
                $eventdata .= ' - ';
            }
            $eventdata .= '<a href="?e='. $key .'">'. $event['name'] .'</a>';
        }
    }
    $html .= $eventdata;
}
$html .= '
    </div>
	<div class="data_period">
		Data ';
if (!empty($_REQUEST['e']) AND  !empty($events[$_REQUEST['e']]['name'])) {
    $html .= 'for '. $events[$_REQUEST['e']]['name'] .'<br>';
    $html .= '(Fra: '. date(($config['date_format']??'d-m-Y'),strtotime($events[$_REQUEST['e']]['datefrom'])) .' til '. date(($config['date_format']??'d-m-Y'),strtotime($events[$_REQUEST['e']]['dateto'])) .')';
}
else {
    $html .= 'from today';
}
$html .= '
        </div>
        <div id="search-field">
         <svg id="search-icon" class="search-icon" viewBox="0 0 24 24">
           <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
           <path d="M0 0h24v24H0z" fill="none" />
         </svg>
         <input type="text" id="searchpokemon" onkeyup="searchpokemon()" placeholder="Search for names..">
        </div>
    </div>
    <div class="sumstats">
        <table class="table total dark-changer">
            <tr>
                <td style="width: 50px;">Total</td><td>'.number_format($total_mons).'</td>
            </tr>
            <tr>
                <td>Shiny</td><td>'.number_format($total_shiny).($total_shiny>0?' (1/'.number_format(round($total_mons/$total_shiny)).')':'') .'</td>
            </tr>
            <tr>
                <td>Hundo</td><td>'.number_format($total_hundo). ($total_hundo>0?' (1/'.number_format(round($total_mons/$total_hundo)).')':'') .'</td>
            </tr>
            <tr>
                <td>Nundo</td><td>'.number_format($total_nundo). ($total_nundo>0?' (1/'.number_format(round($total_mons/$total_nundo)).')':'') .'</td>
            </tr>
        </table>
    </div>
	<div id="shiny_table">
        <table class="table-striped table-hover dark-changer table-sm' . (!empty($_COOKIE["theme"]) ? " dark" : "") . '" id="statstable">
            <thead>
		        <tr>
			        <th scope="col" style="width: 50px;"> </th>
		            <th class="order" scope="col">Pokemon</th>
		            <th class="order" scope="col">Shinies</th>
		            <th class="order" scope="col">Shiny Rate</th>
		            <th class="order" scope="col">Hundoes</th>
		            <th class="order" scope="col">Hundo Rate</th>
		            <th class="order" scope="col">Nundoes</th>
		            <th class="order" scope="col">Nundo Rate</th>
		            <th class="order" scope="col">Total</th>
		        </tr>
		    </thead>
		    <tbody id="table_body">';
foreach ($all as $shiny) {
    $pokemonImageUrl = sprintf($config['images'], (int)$shiny['pokemon_id']);

    $html .= '<tr>';
    $html .= '<td scope="row" class="mobile-hide"><img src="' . $pokemonImageUrl . '" class="icon"/></td>';
    $html .= '<td data-label="Pokemon">' . ($data['pokemon'][$shiny['pokemon_id']]['name']) . ' (#' . $shiny['pokemon_id'] . ') <img src="' . $pokemonImageUrl . '"class="icon desktop-hide"/></td>';
    $html .= '<td data-label="Shinies" class="shiny" data-sort="'. ($shiny['shiny']>0? $shiny['shiny']:0) .'">'. ($shiny['shiny']>0?number_format($shiny['shiny']):'') .'&nbsp;</td>';
    $html .= '<td data-label="Shiny Rate" class="shiny" data-sort="'. ($shiny['shiny']>0? round($shiny['pokemoncount']/$shiny['shiny']):0) .'">'. ($shiny['shiny']>0?'1/' . round($shiny['pokemoncount']/$shiny['shiny']):'') .'&nbsp;</td>';
    $html .= '<td data-label="Hundoes" class="hundo" data-sort="'. ($shiny['hundo']>0? $shiny['hundo']:0) .'">'. ($shiny['hundo']>0?number_format($shiny['hundo']):'') .'&nbsp;</td>';
    $html .= '<td data-label="Hundo Rate" class="hundo" data-sort="'. ($shiny['hundo']>0? round($shiny['pokemoncount']/$shiny['hundo']):0) .'">'. ($shiny['hundo']>0?'1/' . round($shiny['pokemoncount']/$shiny['hundo']):'') .'&nbsp;</td>';
    $html .= '<td data-label="Nundoes" class="nundo" data-sort="'. ($shiny['nundo']>0? $shiny['nundo']:0) .'">'. ($shiny['nundo']>0?number_format($shiny['nundo']):'') .'&nbsp;</td>';
    $html .= '<td data-label="Nundo Rate" class="nundo" data-sort="'. ($shiny['nundo']>0? round($shiny['pokemoncount']/$shiny['nundo']):0) .'">'. ($shiny['nundo']>0?'1/' . round($shiny['pokemoncount']/$shiny['nundo']):'') .'&nbsp;</td>';
    $html .= '<td data-label="Total" data-sort="'. ($shiny['pokemoncount']>0? $shiny['pokemoncount']:0) . '">'. number_format($shiny['pokemoncount']) .'&nbsp;</td>';
    $html .= '</tr>';
}
$html .= '</tbody>
		</table>
	</div>
	<div id="footer"></div> ';
$html .= "
  <script>
  function table_sort() {
        document.querySelectorAll('th.order').forEach(th_elem => {
            let asc = true
            const span_elem = document.createElement('span')
            span_elem.style = 'font-size:0.8rem; margin-left:0.5rem'
            span_elem.innerHTML = '▼'
            th_elem.appendChild(span_elem)
            th_elem.classList.add('order-inactive')
    
            const index = Array.from(th_elem.parentNode.children).indexOf(th_elem)
            th_elem.addEventListener('click', (e) => {
                document.querySelectorAll('th.order').forEach(elem => {
                    elem.classList.remove('order-active')
                    elem.classList.add('order-inactive')
                })
                th_elem.classList.remove('order-inactive')
                th_elem.classList.add('order-active')
    
                if (!asc) {
                    th_elem.querySelector('span').innerHTML = '▲'
                } else {
                    th_elem.querySelector('span').innerHTML = '▼'
                }
                const arr = Array.from(th_elem.closest('table').querySelectorAll('tbody tr'))
                arr.sort((a, b) => {
                    var a_val = a.children[index].innerText
                    if (a.children[index].hasAttribute('data-sort')) {
                        var a_val = a.children[index].dataset.sort
                    }
                    var b_val = b.children[index].innerText
                    if (b.children[index].hasAttribute('data-sort')) {
                        var b_val = b.children[index].dataset.sort
                    }
                    if (parseInt(a_val) !== 'NaN' && parseInt(b_val) !== 'NaN') {
                        return (asc) ? a_val - b_val : b_val - a_val
                    }
                    return (asc) ? a_val.localeCompare(b_val) : b_val.localeCompare(a_val)
                })
                arr.forEach(elem => {
                    th_elem.closest('table').querySelector('tbody').appendChild(elem)
                })
                asc = !asc
            })
        })
    }
    table_sort();

    function searchpokemon() {
      // Declare variables
      var input, filter, table, tr, td, i, txtValue;
      input = document.getElementById('searchpokemon');
      filter = input.value.toUpperCase();
      table = document.getElementById('statstable');
      tr = table.getElementsByTagName('tr');
    
      // Loop through all table rows, and hide those who don't match the search query
      for (i = 0; i < tr.length; i++) {
        td = tr[i].getElementsByTagName('td')[1];
        if (td) {
          txtValue = td.textContent || td.innerText;
          if (txtValue.toUpperCase().indexOf(filter) > -1) {
            tr[i].style.display = '';
          } else {
            tr[i].style.display = 'none';
          }
        }
      }
    }
    searchpokemon();
    function setCookie(c_name, value, exdays) {
        var exdate = new Date();
        exdate.setDate(exdate.getDate() + exdays);
        var c_value = escape(value) + ((exdays == null) ? '' : '; expires=' + exdate.toUTCString()) +';SameSite=Lax; path=/stats';
        document.cookie = c_name + '=' + c_value;
    }

    $('#toggleTheme').on('change', function() {
        if($(this).is(':checked')) {
            setCookie('theme','dark',1000);
            $('.dark-changer').addClass('dark');
        } else {
            setCookie('theme','',0);
            $('.dark-changer').removeClass('dark')
        }
    });
</script>";
$html .= '
</body>
</html>
';
echo $html;
