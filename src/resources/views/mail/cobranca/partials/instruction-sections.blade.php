{{-- Seções do e-mail da Instrução de Cobrança (InstructionEmailSections): o HTML vem do cadastro "Textos de E-mail (IC)", já com os dados da reserva escapados. --}}
    <tr><td>{!! $sections['instruction'] !!}</td></tr>
    @foreach (array_filter([$sections['boleto'], $sections['late_payment']]) as $section)
        <tr><td><hr/></td></tr>
        <tr><td><font face="Arial" color="black"><h3>{{ $section['title'] }}</h3></font></td></tr>
        <tr><td>{!! $section['html'] !!}</td></tr>
    @endforeach
