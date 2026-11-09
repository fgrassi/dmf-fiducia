{{-- Nota da reserva para o agente: mesmo HTML do executeEnviarDebitNote / executeEnviarCreditNote legado. --}}
<table width="840px" border="0" bgcolor="#FFFFFF">
    <tr bgcolor="#7e0001"><td colspan="4">&nbsp;</td></tr>
    <tr><td colspan="4"><font face="Arial">Please note the attached {{ $title }} regarding the following shipment:</font></td></tr>
    @foreach ($rows as [$label, $value])
        <tr><td>{{ $label }}</td><td>{{ $value }}</td></tr>
    @endforeach
    <tr bgcolor="#640000"><td colspan="4" align="center"><font face="Arial" color="white">DMF Logistics do Brasil LTDA</font></td></tr>
</table>
