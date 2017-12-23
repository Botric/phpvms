<table class="table table-hover table-responsive" id="airlines-table">
    <thead>
        <th>Name</th>
        <th>Email</th>
        <th>Registered</th>
        <th class="text-center">Active</th>
        <th></th>
    </thead>
    <tbody>
    @foreach($users as $user)
        <tr>
            <td>{!! $user->name !!}</td>
            <td>{!! $user->email !!}</td>
            <td>{!! show_date($user->created_at) !!}</td>
            <td class="text-center">
                @if($user->state == PilotState::ACTIVE)
                    <span class="label label-success">
                @elseif($user->state == PilotState::PENDING)
                    <span class="label label-warning">
                @else
                    <span class="label label-default">
                @endif
                {!! PilotState::label($user->state) !!}</span>
            </td>
            <td class="text-right">
                {!! Form::open(['route' => ['admin.users.destroy', $user->id], 'method' => 'delete']) !!}
                <a href="{!! route('admin.users.edit', [$user->id]) !!}"
                   class='btn btn-sm btn-success btn-icon'><i class="fa fa-pencil-square-o"></i></a>
                {!! Form::button('<i class="fa fa-times"></i>', ['type' => 'submit', 'class' => 'btn btn-sm btn-danger btn-icon', 'onclick' => "return confirm('Are you sure?')"]) !!}
                {!! Form::close() !!}
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
